<?php

declare(strict_types=1);

namespace MigrationAtlas;

use LogicException;
use MigrationAtlas\Diagnostics\Diagnostic;
use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Diagnostics\DiagnosticCollection;
use MigrationAtlas\Diagnostics\DiagnosticSeverity;
use MigrationAtlas\Discovery\DiscoveryOptions;
use MigrationAtlas\Discovery\MigrationFinder;
use MigrationAtlas\Discovery\MigrationList;
use MigrationAtlas\History\ColumnTraceResolver;
use MigrationAtlas\History\TableHistory;
use MigrationAtlas\History\TableResolution;
use MigrationAtlas\History\TableResolutionKind;
use MigrationAtlas\Normalization\OperationNormalizer;
use MigrationAtlas\Operations\SourceLocation;
use MigrationAtlas\Parsing\MigrationParser;
use MigrationAtlas\Reconstruction\ReconstructionResult;
use MigrationAtlas\Reconstruction\SchemaReconstructor;
use MigrationAtlas\Reconstruction\State\Existence;
use MigrationAtlas\Reconstruction\State\Origin;
use MigrationAtlas\Reconstruction\State\TableState;
use MigrationAtlas\Reconstruction\State\TableStatus;
use MigrationAtlas\Support\AnalysisMeta;
use MigrationAtlas\Support\HistoryData;
use MigrationAtlas\Support\HistoryLineage;
use MigrationAtlas\Support\HistoryResult;
use MigrationAtlas\Support\InspectData;
use MigrationAtlas\Support\InspectResult;
use MigrationAtlas\Support\ResultStatus;
use MigrationAtlas\Support\TraceData;
use MigrationAtlas\Support\TraceResult;

/**
 * The domain orchestrator. Runs the pure analysis pipeline (discovery,
 * parsing, normalization, replay) and resolves one command result at a
 * time. Discovery failures propagate to the command layer; every resolved
 * or error result preserves the envelope, counters, and diagnostics so
 * presentation never needs domain knowledge.
 */
final class Atlas
{
    public function __construct(
        private readonly MigrationFinder $finder,
        private readonly MigrationParser $parser,
        private readonly OperationNormalizer $normalizer,
        private readonly SchemaReconstructor $reconstructor,
        private readonly TableHistory $history = new TableHistory,
        private readonly ColumnTraceResolver $traces = new ColumnTraceResolver,
    ) {}

    public function inspect(string $table, DiscoveryOptions $options, string $connection = 'default'): InspectResult
    {
        [$list, $replay] = $this->analyze($options);

        if (count($list) === 0) {
            return new InspectResult($this->meta('schema:inspect', $table, $connection, null),
                null, $this->noMigrationsResult($list));
        }

        $resolution = $this->history->resolve($replay, $table, $connection);

        if ($resolution->kind === TableResolutionKind::NotFound) {
            return new InspectResult(
                $this->meta('schema:inspect', $table, $connection, $replay, caveated: true),
                null, $this->withError($replay, $list, $this->tableNotFound($replay, $table, $connection)));
        }

        if ($resolution->kind === TableResolutionKind::Ambiguous) {
            return new InspectResult(
                $this->meta('schema:inspect', $table, $connection, $replay, incomplete: true),
                null, $this->withError($replay, $list, $this->ambiguous($table, $resolution->candidates)));
        }

        $lineage = $this->primary($resolution);
        $status = $resolution->kind === TableResolutionKind::Tombstone && $lineage->status === TableStatus::Complete
            ? ResultStatus::Dropped
            : ($lineage->status === TableStatus::Incomplete ? ResultStatus::Incomplete : ResultStatus::Complete);

        $data = new InspectData(
            $lineage->name,
            $connection,
            $status,
            $lineage->existence,
            $lineage->possibleNames,
            $lineage->nameCertain,
            $lineage->reasons,
            $lineage->fidelity(),
            $this->createdBy($lineage),
            $lineage->droppedBy,
            $lineage->columns,
            $lineage->indexes,
            $lineage->foreignKeys,
            $replay->lineageEvents($lineage->lineageId),
        );

        return new InspectResult($this->meta('schema:inspect', $table, $connection, $replay,
            incomplete: $lineage->status === TableStatus::Incomplete), $data, $this->diagnostics($replay, $list));
    }

    public function history(string $table, DiscoveryOptions $options, string $connection = 'default'): HistoryResult
    {
        [$list, $replay] = $this->analyze($options);

        if (count($list) === 0) {
            return new HistoryResult($this->meta('schema:history', $table, $connection, null),
                null, $this->noMigrationsResult($list));
        }

        $resolution = $this->history->resolve($replay, $table, $connection);

        if ($resolution->kind === TableResolutionKind::NotFound) {
            return new HistoryResult(
                $this->meta('schema:history', $table, $connection, $replay, caveated: true),
                null, $this->withError($replay, $list, $this->tableNotFound($replay, $table, $connection)));
        }

        if ($resolution->kind === TableResolutionKind::Ambiguous) {
            return new HistoryResult(
                $this->meta('schema:history', $table, $connection, $replay, incomplete: true),
                null, $this->withError($replay, $list, $this->ambiguous($table, $resolution->candidates)));
        }

        $primary = $this->primary($resolution);
        $lineages = [];

        foreach ($this->history->orderedLineages($replay->registry->byName($connection, $table), $primary) as $lineage) {
            $lineages[] = new HistoryLineage(
                $lineage->lineageId,
                $lineage->name,
                $lineage->existence,
                $lineage->possibleNames,
                $lineage->nameCertain,
                $lineage->reasons,
                $this->createdBy($lineage),
                $lineage->droppedBy,
                $replay->lineageEvents($lineage->lineageId),
            );
        }

        $data = new HistoryData(
            $table,
            $connection,
            $primary->nameCertain ? $primary->name : null,
            $primary->existence,
            $primary->possibleNames,
            $primary->nameCertain,
            $primary->reasons,
            $primary->formerNames,
            $lineages,
        );

        return new HistoryResult($this->meta('schema:history', $table, $connection, $replay,
            incomplete: $primary->status === TableStatus::Incomplete), $data, $this->diagnostics($replay, $list));
    }

    public function trace(string $table, string $column, DiscoveryOptions $options, string $connection = 'default'): TraceResult
    {
        [$list, $replay] = $this->analyze($options);

        if (count($list) === 0) {
            return new TraceResult($this->meta('schema:trace', $table, $connection, null),
                null, $this->noMigrationsResult($list));
        }

        $resolution = $this->history->resolve($replay, $table, $connection);

        if ($resolution->kind === TableResolutionKind::NotFound) {
            return new TraceResult(
                $this->meta('schema:trace', $table, $connection, $replay, caveated: true),
                null, $this->withError($replay, $list, $this->tableNotFound($replay, $table, $connection)));
        }

        if ($resolution->kind === TableResolutionKind::Ambiguous) {
            return new TraceResult(
                $this->meta('schema:trace', $table, $connection, $replay, incomplete: true),
                null, $this->withError($replay, $list, $this->ambiguous($table, $resolution->candidates)));
        }

        $lineage = $this->primary($resolution);
        $trace = $this->traces->trace($replay, $lineage, $column);

        if ($trace === null) {
            $error = new Diagnostic(DiagnosticCode::ColumnNotFound, DiagnosticSeverity::Error,
                "No column identity for '{$lineage->name}.{$column}' is defined by analyzed source.",
                null, null);

            return new TraceResult($this->meta('schema:trace', $table, $connection, $replay, caveated: true),
                null, $this->withError($replay, $list, $error));
        }

        $holder = $trace->holder;
        $existence = match ($lineage->existence) {
            Existence::Absent => Existence::Absent,
            Existence::Unknown => Existence::Unknown,
            Existence::Present => $holder->existence,
        };
        $exists = match ($existence) {
            Existence::Present => true,
            Existence::Absent => false,
            Existence::Unknown => null,
        };

        $data = new TraceData(
            $table,
            $column,
            $lineage->nameCertain ? $lineage->name : null,
            $lineage->nameCertain && $holder->nameCertain ? $trace->currentName : null,
            $exists,
            $existence,
            $lineage->possibleNames,
            $holder->possibleNames,
            $lineage->reasons,
            $holder,
            $trace->events,
            $holder->droppedBy,
        );

        return new TraceResult($this->meta('schema:trace', $table, $connection, $replay,
            incomplete: $lineage->status === TableStatus::Incomplete), $data, $this->diagnostics($replay, $list));
    }

    /**
     * The pure analysis pipeline. Discovery failures propagate to the
     * command layer.
     *
     * @return array{MigrationList, ReconstructionResult}
     */
    private function analyze(DiscoveryOptions $options): array
    {
        $list = $this->finder->find($options);
        $migrations = [];

        foreach ($list->files as $file) {
            $migrations[] = $this->normalizer->normalize($this->parser->parse($file));
        }

        return [$list, $this->reconstructor->replay($migrations)];
    }

    private function meta(string $command, string $table, string $connection, ?ReconstructionResult $replay,
        bool $incomplete = false, bool $caveated = false): AnalysisMeta
    {
        $ledger = $replay?->ledger->entries() ?? [];
        $status = match (true) {
            $incomplete => TableStatus::Incomplete,
            $caveated => TableStatus::Incomplete,
            default => TableStatus::Complete,
        };

        return new AnalysisMeta(
            $command,
            $table,
            $connection,
            $status,
            $ledger !== [],
            count(array_filter($ledger, static fn ($entry): bool => $entry->kind === 'operation')),
            count(array_filter($ledger, static fn ($entry): bool => $entry->kind === 'skipped_migration')),
            $ledger,
            $replay === null ? 0 : $replay->migrationsScanned,
            $replay === null ? 0 : $replay->migrationsAnalyzed,
            $replay === null ? 0 : $replay->migrationsSkipped,
            $replay === null ? 0 : $replay->operationsApplied,
        );
    }

    private function diagnostics(ReconstructionResult $replay, MigrationList $list): DiagnosticCollection
    {
        return new DiagnosticCollection([
            ...$list->diagnostics->items,
            ...$replay->diagnostics->items,
        ]);
    }

    private function withError(ReconstructionResult $replay, MigrationList $list, Diagnostic $error): DiagnosticCollection
    {
        return new DiagnosticCollection([
            ...$list->diagnostics->items,
            ...$replay->diagnostics->items,
            $error,
        ]);
    }

    /**
     * The primary candidate of a resolved or tombstone outcome. The
     * resolution contract guarantees one for those kinds; anything else is
     * an internal invariant violation, never a user-facing result.
     */
    private function primary(TableResolution $resolution): TableState
    {
        $lineage = $resolution->primary();

        if ($lineage === null) {
            throw new LogicException('A resolved or tombstone resolution always carries a primary candidate.');
        }

        return $lineage;
    }

    private function createdBy(TableState $lineage): ?SourceLocation
    {
        // A phantom lineage has no analyzed create; its birth source is the
        // first alter, not a creation site.
        return $lineage->origin === Origin::CreatedHere ? $lineage->source : null;
    }

    private function tableNotFound(ReconstructionResult $replay, string $table, string $connection): Diagnostic
    {
        if ($replay->ledger->entries() !== []) {
            $message = "No analyzed migration resolves '{$table}'. Unresolved operations or skipped files "
                .'in this run could have defined or altered it, so this is not proof of absence.';
        } else {
            $message = "No analyzed migration defines '{$table}'.";
        }

        $partitions = [];

        foreach (array_keys($replay->registry->partitions()) as $partition) {
            if ($partition === $connection) {
                continue;
            }

            foreach ($replay->registry->byName($partition, $table) as $lineage) {
                if ($lineage->existence !== Existence::Absent) {
                    $partitions[$partition] = true;
                }
            }
        }

        if ($partitions !== []) {
            $message .= ' This name matches identities in other partition(s): '.implode(', ', array_keys($partitions)).'.';
        }

        return new Diagnostic(DiagnosticCode::TableNotFound, DiagnosticSeverity::Error, $message, null, null);
    }

    /**
     * The no-migrations error envelope keeps any discovery warnings the
     * empty scan still produced.
     */
    private function noMigrationsResult(MigrationList $list): DiagnosticCollection
    {
        return new DiagnosticCollection([...$list->diagnostics->items, $this->noMigrations()]);
    }

    /**
     * @param  list<TableState>  $candidates
     */
    private function ambiguous(string $table, array $candidates): Diagnostic
    {
        $names = [];

        foreach ($candidates as $lineage) {
            $names[] = "'{$lineage->name}' (lineage {$lineage->lineageId})";
        }

        return new Diagnostic(DiagnosticCode::AmbiguousTableIdentity, DiagnosticSeverity::Error,
            "Multiple live identities match '{$table}': ".implode(', ', $names).'. None is silently chosen.',
            null, null);
    }

    private function noMigrations(): Diagnostic
    {
        return new Diagnostic(DiagnosticCode::NoMigrationsFound, DiagnosticSeverity::Error,
            'No migration files were found in the configured paths.', null, null);
    }
}
