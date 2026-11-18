<?php

declare(strict_types=1);

namespace MigrationAtlas\Cli\Render;

use JsonException;
use MigrationAtlas\Cli\ExitCode;
use MigrationAtlas\History\HistoryEvent;
use MigrationAtlas\Operations\SourceLocation;
use MigrationAtlas\Reconstruction\LedgerEntry;
use MigrationAtlas\Reconstruction\State\ColumnState;
use MigrationAtlas\Reconstruction\State\ForeignKeyState;
use MigrationAtlas\Reconstruction\State\IncompletenessReason;
use MigrationAtlas\Reconstruction\State\IndexState;
use MigrationAtlas\Support\AnalysisMeta;
use MigrationAtlas\Support\AnalysisResult;
use MigrationAtlas\Support\HistoryData;
use MigrationAtlas\Support\HistoryLineage;
use MigrationAtlas\Support\HistoryResult;
use MigrationAtlas\Support\InspectData;
use MigrationAtlas\Support\InspectResult;
use MigrationAtlas\Support\TraceData;
use MigrationAtlas\Support\TraceResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The machine-readable command envelope: deterministic key order, no
 * timestamps, nullable source lines, and the always-present analysis
 * block. Malformed UTF-8 payloads (binary column defaults) are replaced
 * visibly rather than silently transcoded; ordinary output is untouched.
 */
final class JsonRenderer implements Renderer
{
    public function render(OutputInterface $output, AnalysisResult $result, bool $strict = false): int
    {
        try {
            $json = json_encode($this->envelope($result),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $output->writeln('{"diagnostics":[{"severity":"error","code":"MIGRATION_PARSE_FAILURE","message":"The result could not be encoded as JSON."}]}');

            return ExitCode::Failure->value;
        }

        $output->writeln($json);

        return ExitCode::resolve($result, $strict)->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function envelope(AnalysisResult $result): array
    {
        $meta = $result->meta();

        return [
            'meta' => $this->meta($meta),
            'data' => $this->data($result),
            'diagnostics' => $this->diagnostics($result),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(AnalysisMeta $meta): array
    {
        return [
            'command' => $meta->command,
            'table' => $meta->table,
            'connection' => $meta->connection,
            'analysis' => [
                'status' => $meta->status->value,
                'global_uncertainty' => [
                    'present' => $meta->globalUncertainty,
                    'unattributable_operations' => $meta->unattributableOperations,
                    'skipped_migrations' => $meta->skippedMigrations,
                    'sources' => array_map($this->ledgerSource(...), $meta->uncertaintySources),
                ],
                'migrations_scanned' => $meta->migrationsScanned,
                'migrations_analyzed' => $meta->migrationsAnalyzed,
                'migrations_skipped' => $meta->migrationsSkipped,
                'operations_applied' => $meta->operationsApplied,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ledgerSource(LedgerEntry $entry): array
    {
        return [
            'file' => $this->fileName($entry->file),
            'line' => $entry->line,
            'position' => $entry->position,
            'kind' => $entry->kind,
            'reason' => $entry->reason->value,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function data(AnalysisResult $result): ?array
    {
        if ($result instanceof InspectResult) {
            return $result->data === null ? null : $this->inspectData($result->data);
        }

        if ($result instanceof HistoryResult) {
            return $result->data === null ? null : $this->historyData($result->data);
        }

        if ($result instanceof TraceResult) {
            return $result->data === null ? null : $this->traceData($result->data);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectData(InspectData $data): array
    {
        return [
            'name' => $data->name,
            'connection' => $data->connection,
            'status' => $data->status->value,
            'existence' => $data->existence->value,
            'possible_names' => $data->possibleNames,
            'name_certain' => $data->nameCertain,
            'incompleteness_reasons' => array_map($this->reason(...), $data->reasons),
            'fidelity' => $data->fidelity->value,
            'created_by' => $data->createdBy === null ? null : $this->source($data->createdBy),
            'dropped_by' => $data->droppedBy === null ? null : $this->source($data->droppedBy),
            'columns' => array_map($this->column(...), array_values($data->columns)),
            'indexes' => array_map($this->index(...), $data->indexes),
            'foreign_keys' => array_map($this->foreignKey(...), $data->foreignKeys),
            'history_summary' => array_map($this->event(...), $data->historySummary),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function historyData(HistoryData $data): array
    {
        return [
            'table' => $data->table,
            'connection' => $data->connection,
            'current_name' => $data->currentName,
            'existence' => $data->existence->value,
            'possible_names' => $data->possibleNames,
            'name_certain' => $data->nameCertain,
            'incompleteness_reasons' => array_map($this->reason(...), $data->reasons),
            'former_names' => $data->formerNames,
            'lineages' => array_map($this->lineage(...), $data->lineages),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineage(HistoryLineage $lineage): array
    {
        return [
            'lineage_id' => $lineage->lineageId,
            'name' => $lineage->name,
            'existence' => $lineage->existence->value,
            'possible_names' => $lineage->possibleNames,
            'name_certain' => $lineage->nameCertain,
            'incompleteness_reasons' => array_map($this->reason(...), $lineage->reasons),
            'created_by' => $lineage->createdBy === null ? null : $this->source($lineage->createdBy),
            'dropped_by' => $lineage->droppedBy === null ? null : $this->source($lineage->droppedBy),
            'events' => array_map($this->event(...), $lineage->events),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function traceData(TraceData $data): array
    {
        return [
            'requested' => [
                'table' => $data->requestedTable,
                'column' => $data->requestedColumn,
            ],
            'current' => [
                'table' => $data->currentTable,
                'column' => $data->currentColumn,
                'exists' => $data->exists,
                'existence' => $data->existence->value,
                'possible_table_names' => $data->possibleTableNames,
                'possible_column_names' => $data->possibleColumnNames,
            ],
            'incompleteness_reasons' => array_map($this->reason(...), $data->reasons),
            'definition' => $data->definition === null ? null : $this->column($data->definition),
            'events' => array_map($this->event(...), $data->events),
            'dropped_by' => $data->droppedBy === null ? null : $this->source($data->droppedBy),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function column(ColumnState $column): array
    {
        $shape = [
            'name' => $column->name,
            'type' => $column->type,
            'length' => $column->length,
            'precision' => $column->precision,
            'scale' => $column->scale,
            'nullable' => $column->nullable,
            'default' => $column->hasDefault ? $column->default : null,
            'unsigned' => $column->unsigned,
            'auto_increment' => $column->autoIncrement,
            'primary' => $column->primary,
            'fidelity' => $column->fidelity->value,
            'existence' => $column->existence->value,
            'possible_names' => $column->possibleNames,
            'name_certain' => $column->nameCertain,
            'source' => $this->source($column->source),
        ];

        if ($column->allowedValues !== null) {
            $shape['allowed_values'] = $column->allowedValues;
        }

        return $shape;
    }

    /**
     * @return array<string, mixed>
     */
    private function index(IndexState $index): array
    {
        return [
            'name' => $index->name,
            'type' => $index->type,
            'columns' => $index->columns,
            'fidelity' => $index->fidelity->value,
            'source' => $this->source($index->source),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function foreignKey(ForeignKeyState $key): array
    {
        return [
            'name' => $key->name,
            'columns' => $key->columns,
            'referenced_table' => $key->referencedTable,
            'referenced_columns' => $key->referencedColumns,
            'on_delete' => $key->onDelete,
            'on_update' => $key->onUpdate,
            'fidelity' => $key->fidelity->value,
            'source' => $this->source($key->source),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function event(HistoryEvent $event): array
    {
        return [
            'sequence' => $event->sequence,
            'date' => $event->date,
            'operation' => $event->operation,
            'summary' => $event->summary,
            'conditional' => $event->conditional,
            'file' => $this->fileName($event->file),
            'line' => $event->line,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reason(IncompletenessReason $reason): array
    {
        return [
            'origin' => $reason->origin->value,
            'code' => $reason->code->value,
            'file' => $this->fileName($reason->file),
            'line' => $reason->line,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function source(SourceLocation $source): array
    {
        return [
            'file' => $this->fileName($source->migration),
            'line' => $source->line,
        ];
    }

    private function fileName(string $migration): string
    {
        return str_ends_with($migration, '.php') ? $migration : $migration.'.php';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function diagnostics(AnalysisResult $result): array
    {
        $items = [];

        foreach ($result->diagnostics()->items as $diagnostic) {
            $item = [
                'severity' => $diagnostic->severity->value,
                'code' => $diagnostic->code->value,
                'message' => $diagnostic->message,
            ];

            if ($diagnostic->file !== null) {
                $item['file'] = basename($diagnostic->file);
            }

            if ($diagnostic->line !== null) {
                $item['line'] = $diagnostic->line;
            }

            $items[] = $item;
        }

        return $items;
    }
}
