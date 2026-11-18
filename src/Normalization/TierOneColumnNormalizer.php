<?php

declare(strict_types=1);

namespace MigrationAtlas\Normalization;

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Operations\AddColumn;
use MigrationAtlas\Operations\ChangeColumn;
use MigrationAtlas\Operations\ColumnDefinitionDto;
use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Operations\Operation;
use MigrationAtlas\Parsing\RawBlueprintCall;

final class TierOneColumnNormalizer
{
    public function __construct(private readonly ColumnModifierReducer $modifiers = new ColumnModifierReducer) {}

    /** @return list<Operation> */
    public function normalize(RawBlueprintCall $call, string $table, string $connection, NormalizationContext $context): array
    {
        $root = $call->rootCall;
        $method = strtolower($root->method ?? '');
        [$parameters, $required] = match ($method) {
            'id' => [['column'], 0],
            'string' => [['column', 'length'], 1],
            'integer' => [['column', 'autoIncrement', 'unsigned'], 1],
            'timestamps' => [['precision'], 0],
            default => [['column'], 1],
        };
        $arguments = CallArguments::bind($root->arguments, $parameters, $required);
        $raw = $call->modifiers === [] ? $root->source : $call->modifiers[array_key_last($call->modifiers)]->source;

        if ($arguments === null) {
            return [$context->unresolved($root->line, $raw, $table, $connection, DiagnosticCode::UnresolvedDynamicExpression)];
        }

        $name = array_key_exists('column', $arguments) ? $arguments['column'] : ($method === 'id' ? 'id' : null);
        $names = $method === 'timestamps' ? ['created_at', 'updated_at'] : $this->names($name);
        $length = $arguments['length'] ?? null;
        $precision = $arguments['precision'] ?? null;
        $autoIncrement = array_key_exists('autoIncrement', $arguments) ? $arguments['autoIncrement'] : false;
        $unsigned = array_key_exists('unsigned', $arguments) ? $arguments['unsigned'] : false;

        if ($names === null
            || ($method === 'string' && $length !== null && (! is_int($length) || $length < 1))
            || ($method === 'timestamps' && $precision !== null && (! is_int($precision) || $precision < 0))
            || ($method === 'integer' && (! is_bool($autoIncrement) || ! is_bool($unsigned)))) {
            return [$context->unresolved($root->line, $raw, $table, $connection, DiagnosticCode::UnresolvedDynamicExpression)];
        }

        $state = new ColumnModifierState;
        $state->nullable = $method === 'timestamps';

        if ($method === 'timestamps' && $call->modifiers !== []) {
            // Timestamps expand with their own declared nullability and accept
            // no per-column modifiers.
            return [$context->unresolved($call->modifiers[0]->line, $raw, $table, $connection, DiagnosticCode::UnresolvedUnknownMethod)];
        }

        foreach ($call->modifiers as $modifier) {
            $unresolved = $this->modifiers->reduceModifier($modifier, $state, $table, $connection, $context, $raw);

            if ($unresolved !== null) {
                return [$unresolved];
            }
        }

        $attributes = match ($method) {
            'id' => ['unsigned' => true, 'autoIncrement' => true, 'primary' => true],
            'string' => ['length' => $length],
            'integer' => ['autoIncrement' => $autoIncrement, 'unsigned' => $unsigned],
            'timestamps' => ['precision' => $precision],
            default => [],
        };
        $attributes = array_merge($attributes, $state->attributes);
        $type = match ($method) {
            'id' => 'bigint',
            'timestamps' => 'timestamp',
            default => $method,
        };

        if ($state->change) {
            $source = $context->source($root->line);
            $definition = new ColumnDefinitionDto($names[0], $type, $source, $state->nullable, $state->hasDefault, $state->default, $attributes, Fidelity::Reduced);

            return [new ChangeColumn($source, $context->execution, $table, $connection, $definition)];
        }

        $operations = [];

        foreach ($names as $column) {
            $source = $context->source($root->line);
            $definition = new ColumnDefinitionDto($column, $type, $source, $state->nullable, $state->hasDefault, $state->default, $attributes, $state->fidelity());
            $operations[] = new AddColumn($source, $context->execution, $table, $connection, $definition);
        }

        return $operations;
    }

    /** @return non-empty-list<string>|null */
    private function names(mixed $name): ?array
    {
        return is_string($name) && $name !== '' ? [$name] : null;
    }
}
