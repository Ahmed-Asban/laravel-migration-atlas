<?php

declare(strict_types=1);

namespace MigrationAtlas\Reconstruction;

use MigrationAtlas\Diagnostics\Diagnostic;
use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Diagnostics\DiagnosticCollection;
use MigrationAtlas\Diagnostics\DiagnosticSeverity;
use MigrationAtlas\History\HistoryEvent;
use MigrationAtlas\Normalization\NormalizedMigration;
use MigrationAtlas\Operations\AddColumn;
use MigrationAtlas\Operations\AddForeignKey;
use MigrationAtlas\Operations\AddIndex;
use MigrationAtlas\Operations\AlterTable;
use MigrationAtlas\Operations\Attribution;
use MigrationAtlas\Operations\ChangeColumn;
use MigrationAtlas\Operations\CreateTable;
use MigrationAtlas\Operations\DropColumn;
use MigrationAtlas\Operations\DropForeignKey;
use MigrationAtlas\Operations\DropIndex;
use MigrationAtlas\Operations\DropTable;
use MigrationAtlas\Operations\ExecutionCertainty;
use MigrationAtlas\Operations\Operation;
use MigrationAtlas\Operations\RenameColumn;
use MigrationAtlas\Operations\RenameTable;
use MigrationAtlas\Operations\SourceLocation;
use MigrationAtlas\Operations\UnresolvedOperation;
use MigrationAtlas\Reconstruction\State\ColumnState;
use MigrationAtlas\Reconstruction\State\Existence;
use MigrationAtlas\Reconstruction\State\ForeignKeyState;
use MigrationAtlas\Reconstruction\State\IncompletenessOrigin;
use MigrationAtlas\Reconstruction\State\IncompletenessReason;
use MigrationAtlas\Reconstruction\State\IndexState;
use MigrationAtlas\Reconstruction\State\Origin;
use MigrationAtlas\Reconstruction\State\TableState;
use MigrationAtlas\Reconstruction\State\TableStatus;

/**
 * The functional replay fold. Every rule of the approved replay table is
 * applied here: partitions never merge, uncertainty is sticky and never
 * fabricated away, conditional operations retain candidates instead of
 * choosing branches, and whole-file skips cap the entire run.
 */
final class FunctionalSchemaReconstructor implements SchemaReconstructor
{
    private TableRegistry $registry;

    private UncertaintyLedger $ledger;

    /** @var list<HistoryEvent> */
    private array $events = [];

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    /** @var list<IncompletenessReason> */
    private array $capReasons = [];

    private int $sequence = 0;

    private int $analyzed = 0;

    private int $skipped = 0;

    private string $file = '';

    private ?string $date = null;

    public function replay(array $migrations): ReconstructionResult
    {
        $this->registry = TableRegistry::empty();
        $this->ledger = new UncertaintyLedger;
        $this->events = [];
        $this->diagnostics = [];
        $this->capReasons = [];
        $this->sequence = 0;
        $this->analyzed = 0;
        $this->skipped = 0;

        foreach ($migrations as $migration) {
            $this->file = $migration->file->name;
            $this->date = $migration->file->date;
            $this->diagnostics = [...$this->diagnostics, ...$migration->diagnostics->items];

            if (! $migration->parsed) {
                $this->skipped++;
                $this->applySkipMarker($migration);

                continue;
            }

            $this->analyzed++;

            foreach ($migration->operations as $operation) {
                $this->apply($operation);
            }
        }

        return new ReconstructionResult($this->registry, $this->events, $this->ledger,
            new DiagnosticCollection($this->diagnostics), count($migrations), $this->analyzed,
            $this->skipped, $this->sequence);
    }

    private function apply(Operation $operation): void
    {
        if ($operation instanceof UnresolvedOperation) {
            $this->unresolved($operation);

            return;
        }

        if ($operation instanceof CreateTable) {
            $this->createTable($operation);

            return;
        }

        if ($operation instanceof AlterTable) {
            $this->alterTable($operation);

            return;
        }

        if ($operation instanceof DropTable) {
            $this->dropTable($operation);

            return;
        }

        if ($operation instanceof RenameTable) {
            $this->renameTable($operation);
        }
    }

    private function createTable(CreateTable $operation): void
    {
        $connection = $operation->connection;

        if ($this->conditional($operation)) {
            $this->event($operation);

            $live = $this->claimingName($connection, $operation->table);

            if ($live !== []) {
                // The conditional create probably never ran: the existing
                // identity remains the competing candidate.
                foreach ($live as $lineage) {
                    $this->store($connection, $this->touch($lineage, [$this->conditionalReason($operation)], false));
                }

                return;
            }

            $lineage = $this->birth($operation->table, $connection, Existence::Unknown,
                Origin::CreatedHere, $operation->source, [$this->conditionalReason($operation)]);
            $this->store($connection, $lineage);
            $lineage = $this->applyBody($lineage, $operation->operations, $connection);
            $this->store($connection, $lineage);

            return;
        }

        $definitelyLive = $this->claimingName($connection, $operation->table, certainOnly: true, existences: [Existence::Present]);
        $this->event($operation);

        if ($definitelyLive !== [] && $definitelyLive[0]->origin === Origin::Unknown) {
            // A create over a phantom lineage retains the earlier
            // ALTER_UNKNOWN_TABLE uncertainty instead of duplicating.
            $lineage = $this->applyBody($definitelyLive[0], $operation->operations, $connection);
            $this->store($connection, $lineage);

            return;
        }

        if ($definitelyLive !== []) {
            $reason = $this->conflict($operation, DiagnosticCode::DuplicateTableCreate);
            $this->warn(DiagnosticCode::DuplicateTableCreate, $operation);

            foreach ($definitelyLive as $lineage) {
                $this->store($connection, $this->touch($lineage, [$reason], false));
            }

            $lineage = $this->birth($operation->table, $connection, $this->cappedExistence(),
                Origin::CreatedHere, $operation->source, [$reason, ...$this->capReasons]);
            $this->store($connection, $lineage);
            $lineage = $this->applyBody($lineage, $operation->operations, $connection);
            $this->store($connection, $lineage);

            return;
        }

        $possiblyLive = $this->claimingName($connection, $operation->table);

        if ($possiblyLive !== []) {
            // The prior identity is only possibly absent: both candidates
            // and the conflict are retained, never silently replaced.
            $reason = $this->conflict($operation, DiagnosticCode::DuplicateTableCreate);
            $lineage = $this->birth($operation->table, $connection, $this->cappedExistence(),
                Origin::CreatedHere, $operation->source, [$reason, ...$this->capReasons]);
            $this->store($connection, $lineage);
            $lineage = $this->applyBody($lineage, $operation->operations, $connection);
            $this->store($connection, $lineage);

            return;
        }

        $lineage = $this->birth($operation->table, $connection, $this->cappedExistence(),
            Origin::CreatedHere, $operation->source, $this->capReasons);
        $this->store($connection, $lineage);
        $lineage = $this->applyBody($lineage, $operation->operations, $connection);
        $this->store($connection, $lineage);
    }

    private function alterTable(AlterTable $operation): void
    {
        $targets = $this->claimingName($operation->connection, $operation->table);
        $this->event($operation);

        if ($targets === []) {
            // No analyzed migration created this table: a phantom keeps the
            // unknown-origin state and is incomplete from birth.
            $this->warn(DiagnosticCode::AlterUnknownTable, $operation);
            $reason = $this->conflict($operation, DiagnosticCode::AlterUnknownTable);
            $lineage = $this->birth($operation->table, $operation->connection, $this->cappedExistence(),
                Origin::Unknown, $operation->source, [$reason, ...$this->capReasons]);
            $this->store($operation->connection, $lineage);
            $lineage = $this->applyBody($lineage, $operation->operations, $operation->connection);
            $this->store($operation->connection, $lineage);

            return;
        }

        $conditional = $this->conditional($operation);

        foreach ($targets as $index => $lineage) {
            $touched = $conditional
                ? $this->touch($lineage, [$this->conditionalReason($operation)], false)
                : $lineage;
            $this->store($operation->connection, $touched);
            $applied = $this->applyBody($touched, $operation->operations, $operation->connection, announce: $index === 0);
            $this->store($operation->connection, $applied);
        }
    }

    private function dropTable(DropTable $operation): void
    {
        $known = $this->registry->byName($operation->connection, $operation->table);
        $targets = $this->claimingName($operation->connection, $operation->table);
        $this->event($operation);

        if ($known === []) {
            if (! $operation->ifExists) {
                $this->warn(DiagnosticCode::DropUnknownTable, $operation);
            }

            return;
        }

        if ($targets === []) {
            // Only tombstones carry the name: the table is certainly absent
            // and nothing changes.
            return;
        }

        $certain = count($targets) === 1 && $targets[0]->existence === Existence::Present
            && $targets[0]->name === $operation->table && $targets[0]->nameCertain;

        if ($certain && ! $this->conditional($operation)) {
            $dropped = $targets[0]->with(droppedBy: $operation->source, existence: Existence::Absent);
            $this->store($operation->connection, $dropped);

            return;
        }

        // Uncertain or conditional destruction keeps a conservative unknown
        // candidate; a possible drop is recorded, never a definitive
        // tombstone.
        $reason = $this->conditional($operation)
            ? $this->conditionalReason($operation)
            : $this->conflict($operation, DiagnosticCode::DropUnknownTable);

        foreach ($targets as $lineage) {
            $demoted = $lineage->withReasons([$reason])->with(existence: Existence::Unknown, nameCertain: false);
            $this->store($operation->connection, $demoted);
        }
    }

    private function renameTable(RenameTable $operation): void
    {
        $from = $this->claimingName($operation->connection, $operation->from);
        $to = $this->claimingName($operation->connection, $operation->to);
        $this->event($operation);

        if ($from === []) {
            $this->warn(DiagnosticCode::RenameUnknownTable, $operation);

            return;
        }

        if ($to !== []) {
            // The rename is not applied; every involved identity becomes
            // incomplete.
            $reason = $this->conflict($operation, DiagnosticCode::RenameTargetConflict);
            $this->warn(DiagnosticCode::RenameTargetConflict, $operation);

            foreach ([$from, $to] as $group) {
                foreach ($group as $lineage) {
                    $this->store($operation->connection, $this->touch($lineage, [$reason], false));
                }
            }

            return;
        }

        if ($this->conditional($operation)) {
            foreach ($from as $lineage) {
                $possible = $lineage->withPossibleName($operation->to);
                $this->store($operation->connection, $this->touch($possible, [$this->conditionalReason($operation)], false));
            }

            return;
        }

        foreach ($from as $lineage) {
            $renamed = $lineage->with(
                name: $operation->to,
                formerNames: in_array($operation->from, $lineage->formerNames, true)
                    ? $lineage->formerNames
                    : [...$lineage->formerNames, $operation->from],
                possibleNames: in_array($operation->to, $lineage->possibleNames, true)
                    ? $lineage->possibleNames
                    : [...$lineage->possibleNames, $operation->to],
            );
            $this->store($operation->connection, $renamed);
        }
    }

    /**
     * Apply a create or alter body to one lineage. Announced events,
     * diagnostics, and nested unresolved effects fire once for the first
     * compatible candidate; state effects apply to every candidate.
     *
     * @param  list<Operation>  $operations
     */
    private function applyBody(TableState $lineage, array $operations, string $connection, bool $announce = true): TableState
    {
        foreach ($operations as $operation) {
            if ($operation instanceof UnresolvedOperation) {
                if ($announce) {
                    $this->unresolved($operation);
                }

                // Absorb the registry-level demotions the unresolved effect
                // applied to this lineage while keeping the body progress.
                $stored = $this->registry->lineage($connection, $lineage->lineageId);

                if ($stored !== null) {
                    $lineage = $lineage->with(
                        nameCertain: $stored->nameCertain,
                        status: $stored->status,
                        existence: $stored->existence,
                        reasons: $stored->reasons,
                    );
                }

                continue;
            }

            if ($announce) {
                $this->event($operation);
            }

            $lineage = match (true) {
                $operation instanceof AddColumn => $this->addColumn($lineage, $operation),
                $operation instanceof DropColumn => $this->dropColumn($lineage, $operation),
                $operation instanceof RenameColumn => $this->renameColumn($lineage, $operation),
                $operation instanceof ChangeColumn => $this->changeColumn($lineage, $operation),
                $operation instanceof AddIndex => $lineage->withIndex(IndexState::fromDefinition($operation->index)),
                $operation instanceof DropIndex => $this->dropIndex($lineage, $operation),
                $operation instanceof AddForeignKey => $lineage->withForeignKey(ForeignKeyState::fromDefinition($operation->key)),
                $operation instanceof DropForeignKey => $operation->name !== null ? $lineage->withoutForeignKey($operation->name) : $lineage,
                default => $lineage,
            };
        }

        return $lineage;
    }

    private function addColumn(TableState $lineage, AddColumn $operation): TableState
    {
        $existing = $lineage->columns[$operation->column->name] ?? null;

        if ($this->conditional($operation)) {
            if ($existing !== null && $existing->existence === Existence::Present) {
                // The conditional add competes with the existing identity;
                // the existing definition stays as the candidate.
                return $lineage->withReasons([$this->conditionalReason($operation)]);
            }

            $column = ColumnState::fromDefinition($operation->column, Existence::Unknown);

            return $lineage->withColumn($column)->withReasons([$this->conditionalReason($operation)]);
        }

        if ($existing !== null && $existing->existence === Existence::Present) {
            $this->warn(DiagnosticCode::DuplicateColumn, $operation);
            $column = ColumnState::fromDefinition($operation->column, Existence::Present);

            return $lineage->withColumn($column)
                ->withReasons([$this->conflict($operation, DiagnosticCode::DuplicateColumn)]);
        }

        if ($existing !== null && $existing->existence === Existence::Unknown) {
            $column = ColumnState::fromDefinition($operation->column, Existence::Unknown);

            return $lineage->withColumn($column)
                ->withReasons([$this->conflict($operation, DiagnosticCode::DuplicateColumn)]);
        }

        $column = ColumnState::fromDefinition($operation->column, $this->cappedExistence());

        return $lineage->withColumn($column);
    }

    private function dropColumn(TableState $lineage, DropColumn $operation): TableState
    {
        $conditional = $this->conditional($operation);

        foreach ($operation->columns as $name) {
            $matches = [];

            foreach ($lineage->columns as $column) {
                if ($column->name === $name || in_array($name, $column->possibleNames, true)) {
                    $matches[] = $column;
                }
            }

            if ($matches === []) {
                if (! $conditional) {
                    $this->warn(DiagnosticCode::DropUnknownColumn, $operation);
                    $lineage = $lineage->withReasons([$this->conflict($operation, DiagnosticCode::DropUnknownColumn)]);
                }

                continue;
            }

            $certain = count($matches) === 1 && $matches[0]->name === $name
                && $matches[0]->nameCertain && $matches[0]->existence === Existence::Present;

            if ($certain && ! $conditional) {
                $lineage = $lineage->withColumn($matches[0]->withExistence(Existence::Absent, $operation->source));

                continue;
            }

            // A possible-name or uncertain drop keeps the candidate with
            // unknown existence instead of fabricating a tombstone.
            $reason = $conditional
                ? $this->conditionalReason($operation)
                : $this->conflict($operation, DiagnosticCode::DropUnknownColumn);

            foreach ($matches as $column) {
                $lineage = $lineage->withColumn($column->withExistence(Existence::Unknown))->withReasons([$reason]);
            }
        }

        return $lineage;
    }

    private function renameColumn(TableState $lineage, RenameColumn $operation): TableState
    {
        $conditional = $this->conditional($operation);
        $source = $lineage->columns[$operation->from] ?? null;

        if ($source === null || $source->existence === Existence::Absent) {
            if (! $conditional) {
                $this->warn(DiagnosticCode::RenameUnknownColumn, $operation);
                $lineage = $lineage->withReasons([$this->conflict($operation, DiagnosticCode::RenameUnknownColumn)]);
            }

            return $lineage;
        }

        $target = $lineage->columns[$operation->to] ?? null;

        if ($target !== null && $target->existence !== Existence::Absent) {
            $this->warn(DiagnosticCode::RenameTargetConflict, $operation);

            return $lineage->withReasons([
                $this->conflict($operation, DiagnosticCode::RenameTargetConflict),
                $this->conditionalReason($operation),
            ]);
        }

        if ($conditional) {
            $column = $source->withPossibleName($operation->to);

            return $lineage->withColumn($column)->withReasons([$this->conditionalReason($operation)]);
        }

        $column = $source->withRename($operation->to, $operation->source);

        return $lineage->withColumn($column);
    }

    private function changeColumn(TableState $lineage, ChangeColumn $operation): TableState
    {
        $conditional = $this->conditional($operation);
        $target = $lineage->columns[$operation->column->name] ?? null;

        if ($target === null || $target->existence === Existence::Absent) {
            // The catalog carries no dedicated code for a change whose target
            // is absent; DROP_UNKNOWN_COLUMN's documented meaning ("operation
            // target not present at replay time") covers the violation.
            if (! $conditional) {
                $this->warn(DiagnosticCode::DropUnknownColumn, $operation);
                $lineage = $lineage->withReasons([$this->conflict($operation, DiagnosticCode::DropUnknownColumn)]);
            } else {
                $lineage = $lineage->withReasons([$this->conditionalReason($operation)]);
            }

            return $lineage;
        }

        $merged = $target->withChange($operation->column, $operation->source);
        $lineage = $lineage->withColumn($merged);

        return $conditional ? $lineage->withReasons([$this->conditionalReason($operation)]) : $lineage;
    }

    private function dropIndex(TableState $lineage, DropIndex $operation): TableState
    {
        if ($operation->name !== null) {
            return $lineage->withoutIndex($operation->name);
        }

        // A conventional drop without a derivable name removes the index
        // type the operation declares.
        $remaining = array_values(array_filter(
            $lineage->indexes,
            static fn (IndexState $index): bool => $index->type !== $operation->type,
        ));

        return $lineage->with(indexes: $remaining);
    }

    private function unresolved(UnresolvedOperation $operation): void
    {
        $this->ledger->add(new LedgerEntry(
            $operation->source->position,
            $operation->source->migration,
            $operation->source->line,
            'operation',
            $operation->table,
            $operation->connection,
            $operation->reason,
        ));

        $origin = $operation->attribution === Attribution::Unattributable
            ? IncompletenessOrigin::Global
            : IncompletenessOrigin::Direct;
        $reason = new IncompletenessReason($origin, $operation->reason,
            $operation->source->migration, $operation->source->line);

        $connections = match ($operation->attribution) {
            Attribution::Attributed => [$operation->connection],
            Attribution::NameScoped, Attribution::Unattributable => array_keys($this->registry->partitions()),
        };
        $names = match ($operation->attribution) {
            Attribution::Attributed => [$operation->table],
            Attribution::NameScoped => [$operation->table, ...$operation->relatedTables],
            Attribution::Unattributable => null,
        };

        if (is_array($names)) {
            // Attributed and name-scoped operations always carry known
            // names; the DTO types stay nullable.
            $names = array_values(array_filter($names, static fn ($name): bool => $name !== null));
        }

        foreach ($connections as $connection) {
            if ($connection === null) {
                // Attributed operations always carry a literal connection;
                // the type stays nullable because the operation DTO does.
                continue;
            }

            $lineages = $names === null
                ? $this->registry->partition($connection)
                : array_merge(...array_map(
                    fn (string $name): array => $this->registry->byName($connection, $name),
                    $names,
                ));

            foreach ($lineages as $lineage) {
                // Unknown-target operations follow the live-window rule and
                // make every affected name uncertain; a tombstoned name may
                // also have been recreated, so it is never a guaranteed
                // absence again. Scoped operations demote tombstones only
                // and leave live identities untouched.
                $tombstone = $lineage->existence === Existence::Absent;

                $touched = $lineage->withReasons([$reason])->with(
                    existence: $tombstone ? Existence::Unknown : $lineage->existence,
                    nameCertain: $names === null || $tombstone ? false : $lineage->nameCertain,
                );
                $this->store($connection, $touched);
            }
        }
    }

    private function applySkipMarker(NormalizedMigration $migration): void
    {
        $marker = $migration->operations[0] ?? null;
        $code = $marker instanceof UnresolvedOperation ? $marker->reason : DiagnosticCode::MigrationParseFailure;
        $line = $marker?->source->line;

        $this->ledger->add(new LedgerEntry(
            [$migration->file->ordinal, 0],
            $migration->file->name,
            $line,
            'skipped_migration',
            null,
            null,
            $code,
        ));

        $reason = new IncompletenessReason(IncompletenessOrigin::Global, $code, $migration->file->name, $line);
        $this->capReasons[] = $reason;

        // A skipped file has no trustworthy footprint: every existing
        // lineage in every partition is capped incomplete with unknown
        // existence and names.
        foreach ($this->registry->partitions() as $connection => $lineages) {
            foreach ($lineages as $lineage) {
                $capped = $lineage->withReasons([$reason])
                    ->with(existence: Existence::Unknown, nameCertain: false);
                $this->store($connection, $capped);
            }
        }
    }

    /**
     * Every lineage in the connection whose current or possible name claims
     * the given name with one of the allowed existences. Certain-only
     * lookups ignore possible names and uncertain lineages entirely.
     *
     * @param  list<Existence>  $existences
     * @return list<TableState>
     */
    private function claimingName(string $connection, string $name, bool $certainOnly = false, array $existences = [Existence::Present, Existence::Unknown]): array
    {
        $matches = [];

        foreach ($this->registry->byName($connection, $name) as $lineage) {
            $current = $lineage->name === $name && in_array($lineage->existence, $existences, true)
                && (! $certainOnly || $lineage->nameCertain);
            $possible = ! $certainOnly && in_array($name, $lineage->possibleNames, true)
                && in_array($lineage->existence, $existences, true);

            if ($current || $possible) {
                $matches[] = $lineage;
            }
        }

        return $matches;
    }

    /**
     * @param  list<IncompletenessReason>  $reasons
     */
    private function birth(string $name, string $connection, Existence $existence, Origin $origin, SourceLocation $source, array $reasons): TableState
    {
        $lineage = TableState::birth($this->registry->nextLineageId(), $name, $existence,
            $reasons === [] ? TableStatus::Complete : TableStatus::Incomplete, $origin, $source);

        foreach ($reasons as $reason) {
            $lineage = $lineage->withReasons([$reason]);
        }

        if ($this->capReasons !== []) {
            $lineage = $lineage->with(nameCertain: false);
        }

        return $lineage;
    }

    /**
     * Record reasons on a lineage; a tombstone is demoted to an uncertain
     * candidate when its name may have been affected.
     *
     * @param  list<IncompletenessReason>  $reasons
     */
    private function touch(TableState $lineage, array $reasons, bool $demoteTombstone): TableState
    {
        $tombstone = $lineage->existence === Existence::Absent;

        return $lineage->withReasons($reasons)->with(
            existence: $demoteTombstone && $tombstone ? Existence::Unknown : $lineage->existence,
            nameCertain: $demoteTombstone && $tombstone ? false : $lineage->nameCertain,
        );
    }

    private function store(string $connection, TableState $lineage): void
    {
        $this->registry = $this->registry->withLineage($connection, $lineage);
    }

    private function event(Operation $operation): void
    {
        $this->events[] = new HistoryEvent(++$this->sequence, $this->date, $operation->kind,
            $operation->describe(), $this->conditional($operation), $this->file, $operation->source->line);
    }

    private function warn(DiagnosticCode $code, Operation $operation): void
    {
        $this->diagnostics[] = new Diagnostic($code, DiagnosticSeverity::Warning,
            $this->message($code), $operation->source->file, $operation->source->line);
    }

    private function conditional(Operation $operation): bool
    {
        return $operation->execution === ExecutionCertainty::Conditional;
    }

    private function conditionalReason(Operation $operation): IncompletenessReason
    {
        return new IncompletenessReason(IncompletenessOrigin::Conditional, DiagnosticCode::ShouldRunDetected,
            $operation->source->migration, $operation->source->line);
    }

    private function conflict(Operation $operation, DiagnosticCode $code): IncompletenessReason
    {
        return new IncompletenessReason(IncompletenessOrigin::Conflict, $code,
            $operation->source->migration, $operation->source->line);
    }

    private function cappedExistence(): Existence
    {
        return $this->capReasons === [] ? Existence::Present : Existence::Unknown;
    }

    private function message(DiagnosticCode $code): string
    {
        return match ($code) {
            DiagnosticCode::DuplicateTableCreate => 'A create targets a table that is already live in this partition.',
            DiagnosticCode::DuplicateColumn => 'A column is added again while it is already present.',
            DiagnosticCode::AlterUnknownTable => 'Schema::table targets a table that no analyzed migration created.',
            DiagnosticCode::DropUnknownTable => 'A drop targets a table that no analyzed source can identify as live.',
            DiagnosticCode::DropUnknownColumn => 'The operation target is not present at replay time.',
            DiagnosticCode::RenameUnknownTable => 'The rename source cannot be identified as live at replay time.',
            DiagnosticCode::RenameUnknownColumn => 'The renamed column cannot be identified as present at replay time.',
            DiagnosticCode::RenameTargetConflict => 'The rename target is already live; the rename is not applied.',
            default => 'The replayed schema state is incomplete.',
        };
    }
}
