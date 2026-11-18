<?php

declare(strict_types=1);

namespace MigrationAtlas\Reconstruction;

use MigrationAtlas\Diagnostics\DiagnosticCollection;
use MigrationAtlas\History\HistoryEvent;

/**
 * The pure result of replaying analyzed migrations. Carries the final
 * partition-aware registry, the ordered event log, the uncertainty ledger,
 * replay and carried diagnostics, and the reconciled counters.
 */
final readonly class ReconstructionResult
{
    /**
     * @param  list<HistoryEvent>  $events
     */
    public function __construct(
        public TableRegistry $registry,
        public array $events,
        public UncertaintyLedger $ledger,
        public DiagnosticCollection $diagnostics,
        public int $migrationsScanned,
        public int $migrationsAnalyzed,
        public int $migrationsSkipped,
        public int $operationsApplied,
    ) {}
}
