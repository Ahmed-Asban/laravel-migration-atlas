<?php

declare(strict_types=1);

namespace MigrationAtlas\Normalization;

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Operations\AddIndex;
use MigrationAtlas\Operations\DropIndex;
use MigrationAtlas\Operations\IndexDefinitionDto;
use MigrationAtlas\Operations\Operation;
use MigrationAtlas\Operations\UnresolvedOperation;
use MigrationAtlas\Parsing\RawBlueprintCall;
use MigrationAtlas\Parsing\RawMethodCall;

/**
 * Index operations. Default index names follow Laravel's convention exactly:
 * the lowercased table, the ordered columns, and the index type joined by
 * underscores, with dashes and dots replaced by underscores.
 */
final class IndexNormalizer
{
    /** @var array<string, string> */
    private const TYPES = [
        'primary' => 'primary',
        'unique' => 'unique',
        'index' => 'index',
        'fulltext' => 'fulltext',
        'spatialindex' => 'spatialindex',
    ];

    /** @var array<string, string> */
    private const DROP_TYPES = [
        'dropprimary' => 'primary',
        'dropunique' => 'unique',
        'dropindex' => 'index',
        'dropfulltext' => 'fulltext',
        'dropspatialindex' => 'spatialindex',
    ];

    /** @return list<Operation> */
    public function normalize(RawBlueprintCall $call, string $table, string $connection, NormalizationContext $context): array
    {
        $root = $call->rootCall;
        $method = strtolower($root->method ?? '');
        $raw = $call->modifiers === [] ? $root->source : $call->modifiers[array_key_last($call->modifiers)]->source;

        if (isset(self::DROP_TYPES[$method])) {
            return $this->drop($root, $method, $raw, $table, $connection, $context);
        }

        $arguments = CallArguments::bind($root->arguments, ['columns', 'name'], 1);

        if ($arguments === null) {
            return [$this->unresolved($root, $raw, $table, $connection, $context)];
        }

        $columns = $this->columns($arguments['columns'] ?? null);

        if ($columns === null) {
            return [$this->unresolved($root, $raw, $table, $connection, $context)];
        }

        $name = $arguments['name'] ?? null;

        if ($name !== null && (! is_string($name) || $name === '')) {
            return [$this->unresolved($root, $raw, $table, $connection, $context)];
        }

        if ($call->modifiers !== []) {
            return [$context->unresolved($call->modifiers[0]->line, $raw, $table, $connection, DiagnosticCode::UnresolvedUnknownMethod)];
        }

        $type = self::TYPES[$method] ?? null;

        if ($type === null) {
            return [$context->unresolved($root->line, $raw, $table, $connection, DiagnosticCode::UnresolvedUnknownMethod)];
        }

        $source = $context->source($root->line);
        $index = new IndexDefinitionDto($name ?: $this->indexName($type, $columns, $table), $type, $columns, $source);

        return [new AddIndex($source, $context->execution, $table, $connection, $index)];
    }

    /**
     * @return list<Operation>
     */
    private function drop(RawMethodCall $root, string $method, string $raw, string $table, string $connection, NormalizationContext $context): array
    {
        $type = self::DROP_TYPES[$method];
        $arguments = CallArguments::bind($root->arguments, ['index'], $method === 'dropprimary' ? 0 : 1);

        if ($arguments === null) {
            return [$this->unresolved($root, $raw, $table, $connection, $context)];
        }

        $index = $arguments['index'] ?? null;

        if ($index === null) {
            if ($method !== 'dropprimary') {
                return [$this->unresolved($root, $raw, $table, $connection, $context)];
            }

            $source = $context->source($root->line);

            return [new DropIndex($source, $context->execution, $table, $connection, $type, null)];
        }

        if (is_string($index) && $index !== '') {
            $source = $context->source($root->line);

            return [new DropIndex($source, $context->execution, $table, $connection, $type, $index)];
        }

        $columns = $this->columns($index);

        if ($columns === null) {
            return [$this->unresolved($root, $raw, $table, $connection, $context)];
        }

        $source = $context->source($root->line);

        return [new DropIndex($source, $context->execution, $table, $connection, $type, $this->indexName($type, $columns, $table), $columns)];
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
