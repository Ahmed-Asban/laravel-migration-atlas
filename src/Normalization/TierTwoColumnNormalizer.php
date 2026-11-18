<?php

declare(strict_types=1);

namespace MigrationAtlas\Normalization;

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Operations\AddColumn;
use MigrationAtlas\Operations\ChangeColumn;
use MigrationAtlas\Operations\ColumnDefinitionDto;
use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Operations\Operation;
use MigrationAtlas\Operations\UnresolvedOperation;
use MigrationAtlas\Parsing\RawBlueprintCall;
use MigrationAtlas\Parsing\RawMethodCall;

/**
 * Tier-2 column capture: constructs are recorded with their canonical raw
 * type and literal arguments at reduced fidelity, never presented as full
 * semantics.
 */
final class TierTwoColumnNormalizer
{
    public function __construct(
        private readonly SupportMatrix $matrix = new SupportMatrix,
        private readonly ColumnModifierReducer $modifiers = new ColumnModifierReducer,
    ) {}

    /** @return list<Operation> */
    public function normalize(RawBlueprintCall $call, string $table, string $connection, NormalizationContext $context): array
    {
        $root = $call->rootCall;
        $method = strtolower($root->method ?? '');
        $raw = $call->modifiers === [] ? $root->source : $call->modifiers[array_key_last($call->modifiers)]->source;
        [$parameters, $required, $defaultName] = match ($method) {
            'bigincrements', 'increments', 'integerincrements', 'mediumincrements', 'smallincrements', 'tinyincrements' => [['column'], 1, null],
            'biginteger' => [['column', 'autoIncrement', 'unsigned'], 1, null],
            'unsignedinteger', 'unsignedbiginteger' => [['column', 'autoIncrement'], 1, null],
            'datetime', 'time' => [['column', 'precision'], 1, null],
            'decimal' => [['column', 'total', 'places'], 1, null],
            'float' => [['column', 'precision'], 1, null],
            'enum', 'set' => [['column', 'allowed'], 2, null],
            'char' => [['column', 'length'], 1, null],
            'ulid' => [['column', 'length'], 0, 'ulid'],
            'uuid' => [['column'], 0, 'uuid'],
            'ipaddress' => [['column'], 0, 'ip_address'],
            'macaddress' => [['column'], 0, 'mac_address'],
            'softdeletes', 'softdeletestz' => [['column', 'precision'], 0, 'deleted_at'],
            'remembertoken' => [[], 0, 'remember_token'],
            'timestampstz' => [['precision'], 0, null],
            default => [['column'], 1, null],
        };

        $arguments = CallArguments::bind($root->arguments, $parameters, $required);

        if ($arguments === null) {
            return [$this->unresolvedArguments($root, $raw, $table, $connection, $context)];
        }

        if ($method === 'timestampstz') {
            $names = ['created_at', 'updated_at'];
        } elseif ($method === 'remembertoken') {
            $names = ['remember_token'];
        } else {
            $name = $arguments['column'] ?? $defaultName;
            $names = is_string($name) && $name !== '' ? [$name] : null;
        }

        $attributes = $this->attributes($method, $arguments, $root, $raw, $table, $connection, $context);

        if ($attributes instanceof UnresolvedOperation) {
            return [$attributes];
        }

        [$expandedType, $expansion] = match ($method) {
            'softdeletes' => ['timestamp', true],
            'softdeletestz' => ['timestampTz', true],
            'remembertoken' => ['string', true],
            'timestampstz' => ['timestampTz', true],
            default => [null, false],
        };
        $type = $expandedType ?? $this->canonicalType($method);

        $state = new ColumnModifierState;
        $state->nullable = $expansion;

        if ($expansion && $call->modifiers !== []) {
            // Known expansions declare their own modifiers; per-column
            // modifiers stay unsupported exactly like timestamps().
            return [$context->unresolved($call->modifiers[0]->line, $raw, $table, $connection, DiagnosticCode::UnresolvedUnknownMethod)];
        }

        foreach ($call->modifiers as $modifier) {
            $unresolved = $this->modifiers->reduceModifier($modifier, $state, $table, $connection, $context, $raw);

            if ($unresolved !== null) {
                return [$unresolved];
            }
        }

        $attributes = array_merge($attributes, $state->attributes);

        if ($names === null) {
            return [$this->unresolvedArguments($root, $raw, $table, $connection, $context)];
        }

        if ($state->change) {
            $source = $context->source($root->line);
            $definition = new ColumnDefinitionDto($names[0], $type, $source, $state->nullable, $state->hasDefault, $state->default, $attributes, Fidelity::Reduced);

            return [new ChangeColumn($source, $context->execution, $table, $connection, $definition)];
        }

        $operations = [];

        foreach ($names as $column) {
            $source = $context->source($root->line);
            $definition = new ColumnDefinitionDto($column, $type, $source, $state->nullable, $state->hasDefault, $state->default, $attributes, Fidelity::Reduced);
            $operations[] = new AddColumn($source, $context->execution, $table, $connection, $definition);
        }

        return $operations;
    }

    /**
     * The canonical registry name of a Tier-2 construct, preserving the
     * approved spelling of the raw type independently of call-site casing.
     */
    private function canonicalType(string $method): string
    {
        $entry = $this->matrix->find('column', $method);

        return $entry !== null ? $entry->method : $method;
    }

    /**
     * Record the literal arguments of a Tier-2 construct. Auto-increment
     * families carry their construct semantics; everything else keeps the
     * explicitly declared arguments only.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|UnresolvedOperation
     */
    private function attributes(
        string $method,
        array $arguments,
        RawMethodCall $root,
        string $raw,
        string $table,
        string $connection,
        NormalizationContext $context,
    ): array|UnresolvedOperation {
        if (in_array($method, ['bigincrements', 'increments', 'integerincrements', 'mediumincrements', 'smallincrements', 'tinyincrements'], true)) {
            return ['autoIncrement' => true, 'unsigned' => true, 'primary' => true];
        }

        if ($method === 'biginteger' || $method === 'unsignedinteger' || $method === 'unsignedbiginteger') {
            $autoIncrement = array_key_exists('autoIncrement', $arguments) ? $arguments['autoIncrement'] : false;
            $unsigned = array_key_exists('unsigned', $arguments) ? $arguments['unsigned'] : false;

            if (! is_bool($autoIncrement) || ($method === 'biginteger' && ! is_bool($unsigned))) {
                return $this->unresolvedArguments($root, $raw, $table, $connection, $context);
            }

            $attributes = ['autoIncrement' => $autoIncrement];

            if ($method === 'biginteger') {
                $attributes['unsigned'] = $unsigned;
            }

            return $attributes;
        }

        if ($method === 'enum' || $method === 'set') {
            $allowed = $arguments['allowed'] ?? null;

            if (! is_array($allowed) || ! array_is_list($allowed) || $allowed === []) {
                return $this->unresolvedArguments($root, $raw, $table, $connection, $context);
            }

            return ['allowedValues' => $allowed];
        }

        if ($method === 'remembertoken') {
            return ['length' => 100];
        }

        $attributes = [];

        foreach (['precision', 'total', 'places'] as $argument) {
            if (array_key_exists($argument, $arguments)) {
                $value = $arguments[$argument];

                if (! is_int($value) || $value < 0) {
                    return $this->unresolvedArguments($root, $raw, $table, $connection, $context);
                }

                $attributes[$argument] = $value;
            }
        }

        if (array_key_exists('length', $arguments)) {
            $length = $arguments['length'];

            if (! is_int($length) || $length < 1) {
                return $this->unresolvedArguments($root, $raw, $table, $connection, $context);
            }

            $attributes['length'] = $length;
        }

        return $attributes;
    }

    private function unresolvedArguments(
        RawMethodCall $root,
        string $raw,
        string $table,
        string $connection,
        NormalizationContext $context,
    ): UnresolvedOperation {
        return $context->unresolved($root->line, $raw, $table, $connection, DiagnosticCode::UnresolvedDynamicExpression);
    }
}
