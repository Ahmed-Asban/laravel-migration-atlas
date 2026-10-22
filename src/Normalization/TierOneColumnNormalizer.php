<?php

declare(strict_types=1);

namespace MigrationAtlas\Normalization;

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Operations\AddColumn;
use MigrationAtlas\Operations\ColumnDefinitionDto;
use MigrationAtlas\Operations\Operation;
use MigrationAtlas\Parsing\RawBlueprintCall;

final class TierOneColumnNormalizer
{
    public function __construct(private readonly SupportMatrix $matrix = new SupportMatrix) {}

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

        $nullable = $method === 'timestamps';
        $hasDefault = false;
        $default = null;

        foreach ($call->modifiers as $modifier) {
            $entry = $this->matrix->find('modifier', $modifier->method);

            if ($method === 'timestamps' || $entry === null || ! $entry->implemented) {
                return [$context->unresolved($modifier->line, $raw, $table, $connection, DiagnosticCode::UnresolvedUnknownMethod)];
            }

            // Only the documented positional grammar is used for Fluent's
            // magic attribute methods.
            $named = array_filter($modifier->arguments, static fn ($argument): bool => $argument->name !== null);
            $values = $named === [] ? CallArguments::bind($modifier->arguments, ['value'], $modifier->method === 'default' ? 1 : 0) : null;

            if ($values === null) {
                return [$context->unresolved($modifier->line, $raw, $table, $connection, DiagnosticCode::UnresolvedDynamicExpression)];
            }

            $value = array_key_exists('value', $values) ? $values['value'] : true;

            if ($modifier->method === 'nullable' && is_bool($value)) {
                $nullable = $value;
            } elseif ($modifier->method === 'default' && $this->literal($value)) {
                $hasDefault = true;
                $default = $value;
            } else {
                return [$context->unresolved($modifier->line, $raw, $table, $connection, DiagnosticCode::UnresolvedDynamicExpression)];
            }
        }

        $attributes = match ($method) {
            'id' => ['unsigned' => true, 'autoIncrement' => true, 'primary' => true],
            'string' => ['length' => $length],
            'integer' => ['autoIncrement' => $autoIncrement, 'unsigned' => $unsigned],
            'timestamps' => ['precision' => $precision],
            default => [],
        };
        $type = match ($method) {
            'id' => 'bigint',
            'timestamps' => 'timestamp',
            default => $method,
        };
        $operations = [];

        foreach ($names as $column) {
            $source = $context->source($root->line);
            $definition = new ColumnDefinitionDto($column, $type, $source, $nullable, $hasDefault, $default, $attributes);
            $operations[] = new AddColumn($source, $context->execution, $table, $connection, $definition);
        }

        return $operations;
    }

    /** @return non-empty-list<string>|null */
    private function names(mixed $name): ?array
    {
        return is_string($name) && $name !== '' ? [$name] : null;
    }

    private function literal(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! $this->literal($item)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_string($value) || is_int($value) || is_bool($value) || (is_float($value) && is_finite($value));
    }
}
