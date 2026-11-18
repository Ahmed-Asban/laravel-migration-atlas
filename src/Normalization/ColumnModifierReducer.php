<?php

declare(strict_types=1);

namespace MigrationAtlas\Normalization;

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Operations\UnresolvedOperation;
use MigrationAtlas\Parsing\RawMethodCall;
use MigrationAtlas\Parsing\Unresolvable;

final class ColumnModifierReducer
{
    public function __construct(private readonly SupportMatrix $matrix = new SupportMatrix) {}

    /**
     * Reduce one modifier into the chain state. Supported modifiers apply full
     * semantics; partially supported modifiers record static data and keep
     * the column reduced; anything else stays visibly unresolved. Fluent
     * attribute setters accept the documented positional grammar only.
     */
    public function reduceModifier(
        RawMethodCall $modifier,
        ColumnModifierState $state,
        string $table,
        string $connection,
        NormalizationContext $context,
        string $raw,
    ): ?UnresolvedOperation {
        $entry = $this->matrix->find('modifier', $modifier->method);

        if ($entry === null || ! $entry->implemented) {
            return $context->unresolved($modifier->line, $raw, $table, $connection, DiagnosticCode::UnresolvedUnknownMethod);
        }

        $named = array_filter($modifier->arguments, static fn ($argument): bool => $argument->name !== null);
        $values = $named === [] ? CallArguments::bind($modifier->arguments, ['value'], $modifier->method === 'default' ? 1 : 0) : null;

        if ($values === null) {
            return $context->unresolved($modifier->line, $raw, $table, $connection, DiagnosticCode::UnresolvedDynamicExpression);
        }

        $value = array_key_exists('value', $values) ? $values['value'] : true;

        if ($modifier->method === 'nullable' && is_bool($value)) {
            $state->nullable = $value;

            return null;
        }

        if ($modifier->method === 'nullable') {
            return $context->unresolved($modifier->line, $raw, $table, $connection, DiagnosticCode::UnresolvedDynamicExpression);
        }

        if ($modifier->method === 'default') {
            if ($this->literal($value)) {
                $state->hasDefault = true;
                $state->default = $value;

                return null;
            }

            if ($value instanceof Unresolvable && $this->dynamicDefault($value)) {
                $state->hasDefault = true;
                $state->default = '[dynamic]';
                $state->reduced = true;

                return null;
            }

            return $context->unresolved($modifier->line, $raw, $table, $connection, DiagnosticCode::UnresolvedDynamicExpression);
        }

        // Remaining implemented modifiers are partially supported: they record
        // static data and mark the column reduced, never full fidelity.
        $state->reduced = true;

        if ($modifier->method === 'change' && $value === true) {
            $state->change = true;

            return null;
        }

        if ($modifier->method === 'useCurrent' && is_bool($value)) {
            if ($value) {
                $state->hasDefault = true;
                $state->default = 'CURRENT_TIMESTAMP';
            }

            return null;
        }

        if ($modifier->method === 'useCurrentOnUpdate' && is_bool($value)) {
            if ($value) {
                $state->attributes['onUpdate'] = 'CURRENT_TIMESTAMP';
            }

            return null;
        }

        if ($modifier->method === 'unsigned' && is_bool($value)) {
            $state->attributes['unsigned'] = $value;

            return null;
        }

        if ($modifier->method === 'first' && is_bool($value)) {
            $state->attributes['first'] = $value;

            return null;
        }

        if (in_array($modifier->method, ['after', 'charset', 'collation'], true) && is_string($value) && $value !== '') {
            $state->attributes[lcfirst($modifier->method)] = $value;

            return null;
        }

        if ($modifier->method === 'comment' && is_string($value)) {
            $state->attributes['comment'] = $value;

            return null;
        }

        return $context->unresolved($modifier->line, $raw, $table, $connection, DiagnosticCode::UnresolvedDynamicExpression);
    }

    /**
     * Recognized dynamic default expressions are recorded as an explicit
     * marker instead of pretending a static value exists.
     */
    private function dynamicDefault(Unresolvable $value): bool
    {
        $expression = ltrim($value->expression, '\\');

        return (bool) preg_match('/^(now|(?:DB|Illuminate\\\\Support\\\\Facades\\\\DB|Illuminate\\\\Database\\\\Facades\\\\DB)::raw)\s*\(/i', $expression);
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
