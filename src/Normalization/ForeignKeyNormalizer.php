<?php

declare(strict_types=1);

namespace MigrationAtlas\Normalization;

use Illuminate\Support\Str;
use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Operations\AddColumn;
use MigrationAtlas\Operations\AddForeignKey;
use MigrationAtlas\Operations\ChangeColumn;
use MigrationAtlas\Operations\ColumnDefinitionDto;
use MigrationAtlas\Operations\DropForeignKey;
use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Operations\ForeignKeyDefinitionDto;
use MigrationAtlas\Operations\Operation;
use MigrationAtlas\Operations\UnresolvedOperation;
use MigrationAtlas\Parsing\RawBlueprintCall;
use MigrationAtlas\Parsing\RawMethodCall;

/**
 * Foreign-key operations. Literal fluent chains are captured fully; the
 * convention table of constrained()/foreignIdFor() follows Laravel's exact
 * static derivation, and reduced fidelity marks model-derived conventions
 * that static analysis cannot verify.
 */
final class ForeignKeyNormalizer
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

        return match ($method) {
            'foreign' => $this->foreign($call, $table, $connection, $context),
            'dropforeign' => $this->dropForeign($call, $table, $connection, $context),
            'foreignid' => $this->foreignId($call, $table, $connection, $context),
            'foreignidfor' => $this->foreignIdFor($call, $table, $connection, $context),
            default => [$context->unresolved($root->line, $root->source, $table, $connection, DiagnosticCode::UnresolvedUnknownMethod)],
        };
    }

    /** @return list<Operation> */
    private function foreign(RawBlueprintCall $call, string $table, string $connection, NormalizationContext $context): array
    {
        $root = $call->rootCall;
        $raw = $call->modifiers === [] ? $root->source : $call->modifiers[array_key_last($call->modifiers)]->source;
        $arguments = CallArguments::bind($root->arguments, ['columns', 'name'], 1);

        if ($arguments === null) {
            return [$this->unresolved($root, $raw, $table, $connection, $context)];
        }

        $columns = $this->columns($arguments['columns'] ?? null);
        $name = $arguments['name'] ?? null;

        if ($columns === null || ($name !== null && (! is_string($name) || $name === ''))) {
            return [$this->unresolved($root, $raw, $table, $connection, $context)];
        }

        $key = new ForeignKeyState;
        $key->columns = $columns;
        $key->name = $name;
        $key->touched = true;

        foreach ($call->modifiers as $modifier) {
            $unresolved = $this->keyModifier($modifier, $key, false, null, null, $table, $connection, $context, $raw);

            if ($unresolved !== null) {
                return [$unresolved];
            }
        }

        return $this->completeForeignKey($key, $root, $call, $table, $connection, $context, $raw, Fidelity::Full);
    }

    /** @return list<Operation> */
    private function dropForeign(RawBlueprintCall $call, string $table, string $connection, NormalizationContext $context): array
    {
        $root = $call->rootCall;
        $raw = $call->modifiers === [] ? $root->source : $call->modifiers[array_key_last($call->modifiers)]->source;
        $arguments = CallArguments::bind($root->arguments, ['index'], 1);

        if ($arguments === null) {
            return [$this->unresolved($root, $raw, $table, $connection, $context)];
        }

        if ($call->modifiers !== []) {
            return [$context->unresolved($call->modifiers[0]->line, $raw, $table, $connection, DiagnosticCode::UnresolvedUnknownMethod)];
        }

        $index = $arguments['index'] ?? null;
        $source = $context->source($root->line);

        if (is_string($index) && $index !== '') {
            return [new DropForeignKey($source, $context->execution, $table, $connection, $index)];
        }

        $columns = $this->columns($index);

        if ($columns === null) {
            return [$this->unresolved($root, $raw, $table, $connection, $context)];
        }

        return [new DropForeignKey($source, $context->execution, $table, $connection, $this->indexName('foreign', $columns, $table), $columns)];
    }

    /** @return list<Operation> */
    private function foreignId(RawBlueprintCall $call, string $table, string $connection, NormalizationContext $context): array
    {
        $root = $call->rootCall;
        $raw = $call->modifiers === [] ? $root->source : $call->modifiers[array_key_last($call->modifiers)]->source;
        $arguments = CallArguments::bind($root->arguments, ['column'], 1);
        $column = $arguments !== null ? ($arguments['column'] ?? null) : null;

        if (! is_string($column) || $column === '') {
            return [$this->unresolved($root, $raw, $table, $connection, $context)];
        }

        return $this->foreignIdChain($call, $column, null, $table, $connection, $context, 'foreignId');
    }

    /** @return list<Operation> */
    private function foreignIdFor(RawBlueprintCall $call, string $table, string $connection, NormalizationContext $context): array
    {
        $root = $call->rootCall;
        $raw = $call->modifiers === [] ? $root->source : $call->modifiers[array_key_last($call->modifiers)]->source;
        $arguments = CallArguments::bind($root->arguments, ['model', 'column'], 1);
        $model = $arguments !== null ? ($arguments['model'] ?? null) : null;
        $explicit = $arguments !== null ? ($arguments['column'] ?? null) : null;

        if (! is_string($model) || $model === '' || ($explicit !== null && (! is_string($explicit) || $explicit === ''))) {
            // A model identity is only statically known when the parser
            // resolved a fully-qualified class literal; dynamic models and
            // unimported names stay unresolved.
            return [$this->unresolved($root, $raw, $table, $connection, $context)];
        }

        $class = $this->classBasename($model);
        $conventionTable = Str::snake(Str::pluralStudly($class));
        $column = is_string($explicit) ? $explicit : Str::snake($class).'_id';

        return $this->foreignIdChain($call, $column, $conventionTable, $table, $connection, $context, 'foreignIdFor');
    }

    /**
     * Shared chain handling for foreignId()/foreignIdFor() roots: column
     * modifiers and foreign-key modifiers interleave in declared order, but a
     * key command starts only through constrained() or references().
     *
     * @return list<Operation>
     */
    private function foreignIdChain(
        RawBlueprintCall $call,
        string $column,
        ?string $conventionTable,
        string $table,
        string $connection,
        NormalizationContext $context,
        string $type,
    ): array {
        $root = $call->rootCall;
        $raw = $call->modifiers === [] ? $root->source : $call->modifiers[array_key_last($call->modifiers)]->source;
        $state = new ColumnModifierState;
        $key = new ForeignKeyState;
        $key->columns = [$column];
        $key->referencedColumns = $type === 'foreignIdFor' ? ['id'] : null;
        $key->referencedTable = $conventionTable;
        $key->reduced = $type === 'foreignIdFor';

        foreach ($call->modifiers as $modifier) {
            $entry = $this->matrix->find('foreign_modifier', $modifier->method);

            if ($entry !== null) {
                $unresolved = $this->keyModifier($modifier, $key, true, $column, $conventionTable, $table, $connection, $context, $raw);

                if ($unresolved !== null) {
                    return [$unresolved];
                }

                continue;
            }

            $unresolved = $this->modifiers->reduceModifier($modifier, $state, $table, $connection, $context, $raw);

            if ($unresolved !== null) {
                return [$unresolved];
            }
        }

        $source = $context->source($root->line);
        $attributes = array_merge(
            $type === 'foreignIdFor' && $conventionTable !== null ? ['references' => 'id', 'table' => $conventionTable] : [],
            ['unsigned' => true],
            $state->attributes,
        );
        $definition = new ColumnDefinitionDto($column, $type, $source, $state->nullable, $state->hasDefault, $state->default, $attributes, Fidelity::Reduced);
        $operations = [];

        if ($state->change) {
            $operations[] = new ChangeColumn($source, $context->execution, $table, $connection, $definition);
        } else {
            $operations[] = new AddColumn($source, $context->execution, $table, $connection, $definition);
        }

        if ($key->touched) {
            $operations = array_merge($operations, $this->completeForeignKey($key, $root, $call, $table, $connection, $context, $raw, $key->reduced ? Fidelity::Reduced : Fidelity::Full));
        }

        return $operations;
    }

    /**
     * Apply one foreign-key chain modifier. Modifiers that would only set
     * dead Fluent attributes before a key command exists stay unresolved.
     */
    private function keyModifier(
        RawMethodCall $modifier,
        ForeignKeyState $key,
        bool $referencesParameters,
        ?string $column,
        ?string $conventionTable,
        string $table,
        string $connection,
        NormalizationContext $context,
        string $raw,
    ): ?UnresolvedOperation {
        $method = strtolower($modifier->method ?? '');

        if ($method === 'constrained') {
            if ($column === null) {
                // constrained() has no meaning on a foreign() root.
                return $context->unresolved($modifier->line, $raw, $table, $connection, DiagnosticCode::UnresolvedUnknownMethod);
            }

            $arguments = CallArguments::bind($modifier->arguments, ['table', 'column', 'indexName'], 0);
            $tableArgument = $arguments['table'] ?? null;
            $columnArgument = $arguments['column'] ?? null;
            $indexName = $arguments['indexName'] ?? null;

            if (($tableArgument !== null && (! is_string($tableArgument) || $tableArgument === ''))
                || ($columnArgument !== null && (! is_string($columnArgument) || $columnArgument === ''))
                || ($indexName !== null && (! is_string($indexName) || $indexName === ''))) {
                return $context->unresolved($modifier->line, $raw, $table, $connection, DiagnosticCode::UnresolvedDynamicExpression);
            }

            $referenced = is_string($columnArgument) ? $columnArgument : ($key->referencedColumns[0] ?? 'id');
            $position = strrpos($column, '_'.$referenced);
            $prefix = $position === false ? $column : substr($column, 0, $position);

            $key->referencedColumns = [$referenced];
            $key->referencedTable = is_string($tableArgument)
                ? $tableArgument
                : ($conventionTable ?? ($prefix !== '' ? Str::plural($prefix) : null));
            $key->name = is_string($indexName) ? $indexName : $key->name;
            $key->touched = true;

            return null;
        }

        if ($method === 'references') {
            $parameters = $referencesParameters ? ['column', 'indexName'] : ['columns'];
            $arguments = CallArguments::bind($modifier->arguments, $parameters, 1);
            $referenced = $arguments[$parameters[0]] ?? null;
            $indexName = $referencesParameters ? ($arguments['indexName'] ?? null) : null;
            $columns = $this->columns($referenced);

            if ($arguments === null || $columns === null || ($indexName !== null && (! is_string($indexName) || $indexName === ''))) {
                return $context->unresolved($modifier->line, $raw, $table, $connection, DiagnosticCode::UnresolvedDynamicExpression);
            }

            $key->referencedColumns = $columns;

            if (is_string($indexName)) {
                $key->name = $indexName;
            }

            $key->touched = true;

            return null;
        }

        if (! $key->touched) {
            // on()/onDelete()/cascade… before constrained()/references() set
            // dead Fluent attributes on a foreignId column; no key command
            // exists, so the effect stays visibly unresolved.
            return $context->unresolved($modifier->line, $raw, $table, $connection, DiagnosticCode::UnresolvedUnknownMethod);
        }

        if ($method === 'on') {
            $arguments = CallArguments::bind($modifier->arguments, ['table'], 1);
            $target = $arguments !== null ? ($arguments['table'] ?? null) : null;

            if (! is_string($target) || $target === '') {
                return $context->unresolved($modifier->line, $raw, $table, $connection, DiagnosticCode::UnresolvedDynamicExpression);
            }

            $key->referencedTable = $target;

            return null;
        }

        if ($method === 'ondelete' || $method === 'onupdate') {
            $arguments = CallArguments::bind($modifier->arguments, ['action'], 1);
            $action = $arguments !== null ? ($arguments['action'] ?? null) : null;

            if (! is_string($action) || $action === '') {
                return $context->unresolved($modifier->line, $raw, $table, $connection, DiagnosticCode::UnresolvedDynamicExpression);
            }

            if ($method === 'ondelete') {
                $key->onDelete = $action;
            } else {
                $key->onUpdate = $action;
            }

            return null;
        }

        // cascadeOnDelete/restrictOnDelete/nullOnDelete/cascadeOnUpdate are
        // real no-argument methods on the framework definition.
        $arguments = CallArguments::bind($modifier->arguments, [], 0);
        $action = match ($method) {
            'cascadeondelete' => 'cascade',
            'restrictondelete' => 'restrict',
            'nullondelete' => 'set null',
            'cascadeonupdate' => 'cascade',
            default => null,
        };

        if ($arguments === null || $action === null) {
            return $context->unresolved($modifier->line, $raw, $table, $connection, DiagnosticCode::UnresolvedUnknownMethod);
        }

        if ($method === 'cascadeonupdate') {
            $key->onUpdate = $action;
        } else {
            $key->onDelete = $action;
        }

        return null;
    }

    /**
     * Assemble the foreign-key operation once the chain ended.
     *
     * @return list<Operation>
     */
    private function completeForeignKey(
        ForeignKeyState $key,
        RawMethodCall $root,
        RawBlueprintCall $call,
        string $table,
        string $connection,
        NormalizationContext $context,
        string $raw,
        Fidelity $fidelity,
    ): array {
        if ($key->referencedColumns === null || $key->referencedTable === null || $key->columns === null || $key->columns === []) {
            // An incomplete fluent chain cannot be attributed as a complete
            // key; the chain end is the most precise failure site.
            $line = $call->modifiers === [] ? $root->line : $call->modifiers[array_key_last($call->modifiers)]->line;

            return [$context->unresolved($line, $raw, $table, $connection, DiagnosticCode::UnresolvedDynamicExpression)];
        }

        $source = $context->source($root->line);
        $definition = new ForeignKeyDefinitionDto(
            $key->name ?? $this->indexName('foreign', $key->columns, $table),
            $key->columns,
            $key->referencedTable,
            $key->referencedColumns,
            $source,
            $key->onDelete,
            $key->onUpdate,
            $fidelity,
        );

        return [new AddForeignKey($source, $context->execution, $table, $connection, $definition)];
    }

    /**
     * @return non-empty-list<string>|null
     */
    private function columns(mixed $columns): ?array
    {
        $values = is_array($columns) ? $columns : [$columns];
        $resolved = [];

        foreach ($values as $column) {
            if (! is_string($column) || $column === '') {
                return null;
            }

            $resolved[] = $column;
        }

        return $resolved === [] ? null : $resolved;
    }

    /**
     * @param  non-empty-list<string>  $columns
     */
    private function indexName(string $type, array $columns, string $table): string
    {
        return str_replace(['-', '.'], '_', strtolower($table.'_'.implode('_', $columns).'_'.$type));
    }

    private function classBasename(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    private function unresolved(
        RawMethodCall $root,
        string $raw,
        string $table,
        string $connection,
        NormalizationContext $context,
    ): UnresolvedOperation {
        return $context->unresolved($root->line, $raw, $table, $connection, DiagnosticCode::UnresolvedDynamicExpression);
    }
}
