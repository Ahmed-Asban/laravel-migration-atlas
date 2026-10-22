<?php

declare(strict_types=1);

namespace MigrationAtlas\Normalization;

use MigrationAtlas\Diagnostics\Diagnostic;
use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Diagnostics\DiagnosticCollection;
use MigrationAtlas\Diagnostics\DiagnosticSeverity;
use MigrationAtlas\Operations\AddColumn;
use MigrationAtlas\Operations\AlterTable;
use MigrationAtlas\Operations\CreateTable;
use MigrationAtlas\Operations\DropColumn;
use MigrationAtlas\Operations\DropTable;
use MigrationAtlas\Operations\ExecutionCertainty;
use MigrationAtlas\Operations\Operation;
use MigrationAtlas\Operations\RenameColumn;
use MigrationAtlas\Operations\RenameTable;
use MigrationAtlas\Operations\SourceLocation;
use MigrationAtlas\Operations\UnresolvedOperation;
use MigrationAtlas\Parsing\ParsedMigration;
use MigrationAtlas\Parsing\RawBlueprintCall;
use MigrationAtlas\Parsing\RawSchemaCall;

final class OperationNormalizer
{
    public function __construct(
        private readonly SupportMatrix $matrix = new SupportMatrix,
        private readonly TierOneColumnNormalizer $columns = new TierOneColumnNormalizer,
    ) {}

    public function normalize(ParsedMigration $migration): NormalizedMigration
    {
        $execution = $migration->conditional ? ExecutionCertainty::Conditional : ExecutionCertainty::Always;

        if (! $migration->parsed) {
            return $this->skipped($migration, $execution);
        }

        $context = new NormalizationContext($migration->file, $execution, $migration->diagnostics->items);
        $operations = [];

        foreach ($migration->calls as $call) {
            $operations[] = $this->schema($call, $context);
        }

        return new NormalizedMigration($migration->file, $operations, new DiagnosticCollection($context->diagnostics), true);
    }

    private function skipped(ParsedMigration $migration, ExecutionCertainty $execution): NormalizedMigration
    {
        $diagnostics = $migration->diagnostics->items;
        $root = null;

        foreach ($diagnostics as $diagnostic) {
            if (in_array($diagnostic->code, [DiagnosticCode::MigrationSyntaxError, DiagnosticCode::MigrationParseFailure,
                DiagnosticCode::FileTooLarge, DiagnosticCode::MigrationSourceUnreadable,
                DiagnosticCode::MigrationClassNotFound, DiagnosticCode::UpMethodMissing], true)) {
                $root = $diagnostic;
                break;
            }
        }

        if ($root === null) {
            $root = new Diagnostic(DiagnosticCode::MigrationParseFailure, DiagnosticSeverity::Warning,
                'The migration body could not be established.', $migration->file->absolutePath);
            $diagnostics[] = $root;
        }

        $source = new SourceLocation($migration->file->absolutePath, $migration->file->name, $root->line, $migration->file->ordinal, 0);
        $marker = new UnresolvedOperation($source, $execution, null, null, $root->code, '', true, cause: $root->code);

        return new NormalizedMigration($migration->file, [$marker], new DiagnosticCollection($diagnostics), false);
    }

    private function schema(RawSchemaCall $call, NormalizationContext $context): Operation
    {
        $entry = $this->matrix->find('schema', $call->method);
        $method = strtolower($call->method ?? '');

        if ($call->rawSql) {
            return $context->unresolved($call->line, $call->source, null, $call->connection, DiagnosticCode::RawSqlDetected);
        }

        if ($entry === null || ! $entry->implemented || ! in_array($entry->normalizer, ['create_table', 'alter_table', 'drop_table', 'rename_table'], true)) {
            // A macro's apparent table argument does not establish its footprint.
            return $context->unresolved($call->line, $call->source, null, $call->connection, DiagnosticCode::UnresolvedUnknownMethod);
        }

        $parameters = match ($entry->normalizer) {
            'create_table', 'alter_table' => ['table', 'callback'],
            'rename_table' => ['from', 'to'],
            default => ['table'],
        };
        $arguments = CallArguments::bind($call->arguments, $parameters, count($parameters));
        $names = [];

        foreach ($parameters as $index => $parameter) {
            if ($parameter !== 'callback') {
                $value = $arguments !== null ? ($arguments[$parameter] ?? null) : $this->knownArgument($call, $parameter, $index);
                $names[] = is_string($value) && $value !== '' ? $value : null;
            }
        }

        $table = in_array(null, $names, true) ? null : $names[0];
        $related = array_values(array_unique(array_filter($table === null ? $names : array_slice($names, 1), static fn ($name): bool => $name !== null)));
        $cause = match (true) {
            $call->unresolvedRegion => DiagnosticCode::UnresolvedConditionalRegion,
            $arguments === null || $table === null || ! $call->callbackResolved => DiagnosticCode::UnresolvedDynamicExpression,
            $call->connection === null => DiagnosticCode::UnattributableOperation,
            default => null,
        };

        if ($cause !== null) {
            return $context->unresolved($call->line, $call->source, $table, $call->connection,
                $cause, $related);
        }

        $source = $context->source($call->line);

        if ($entry->normalizer === 'drop_table') {
            return new DropTable($source, $context->execution, $table, $call->connection, $method === 'dropifexists');
        }

        if ($entry->normalizer === 'rename_table') {
            return new RenameTable($source, $context->execution, $table, $related[0] ?? $table, $call->connection);
        }

        $body = [];

        foreach ($call->blueprintCalls as $blueprint) {
            array_push($body, ...$this->blueprint($blueprint, $table, $call->connection, $context));
        }

        if ($entry->normalizer === 'create_table') {
            $columns = [];

            foreach ($body as $operation) {
                if ($operation instanceof AddColumn) {
                    $columns[] = $operation->column;
                }
            }

            return new CreateTable($source, $context->execution, $table, $call->connection, $columns, $body);
        }

        return new AlterTable($source, $context->execution, $table, $call->connection, $body);
    }

    /** @return list<Operation> */
    private function blueprint(RawBlueprintCall $call, string $table, string $connection, NormalizationContext $context): array
    {
        $root = $call->rootCall;
        $entry = $this->matrix->blueprint($root->method);
        $raw = $call->modifiers === [] ? $root->source : $call->modifiers[array_key_last($call->modifiers)]->source;

        if ($call->unresolvedRegion) {
            return [$context->unresolved($root->line, $raw, $table, $connection, DiagnosticCode::UnresolvedConditionalRegion)];
        }

        if ($entry === null || ! $entry->implemented) {
            return [$context->unresolved($root->line, $raw, $table, $connection, DiagnosticCode::UnresolvedUnknownMethod)];
        }

        if ($entry->normalizer === 'tier_one_column') {
            return $this->columns->normalize($call, $table, $connection, $context);
        }

        if ($call->modifiers !== []) {
            return [$context->unresolved($call->modifiers[0]->line, $raw, $table, $connection, DiagnosticCode::UnresolvedUnknownMethod)];
        }

        $parameters = $entry->normalizer === 'drop_column' ? ['columns'] : ['from', 'to'];
        $arguments = CallArguments::bind($root->arguments, $parameters, count($parameters));
        $names = $this->columnNames($arguments === null ? [] : ($entry->normalizer === 'drop_column'
            ? (is_array($arguments['columns']) ? $arguments['columns'] : [$arguments['columns']])
            : [$arguments['from'], $arguments['to']]));

        if ($names === null) {
            return [$context->unresolved($root->line, $raw, $table, $connection, DiagnosticCode::UnresolvedDynamicExpression)];
        }

        $source = $context->source($root->line);

        return [$entry->normalizer === 'drop_column'
            ? new DropColumn($source, $context->execution, $table, $connection, $names)
            : new RenameColumn($source, $context->execution, $table, $connection, $names[0], $names[1])];
    }

    /** @return non-empty-list<string>|null */
    private function columnNames(mixed $names): ?array
    {
        if (! is_array($names) || ! array_is_list($names) || $names === []) {
            return null;
        }

        $resolved = [];

        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                return null;
            }

            $resolved[] = $name;
        }

        return $resolved;
    }

    private function knownArgument(RawSchemaCall $call, string $name, int $position): mixed
    {
        foreach ($call->arguments as $index => $argument) {
            if (! $argument->unpack && ($argument->name === $name || ($argument->name === null && $index === $position))) {
                return $argument->value;
            }
        }

        return null;
    }
}
