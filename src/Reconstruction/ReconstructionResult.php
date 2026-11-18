<?php

declare(strict_types=1);

namespace MigrationAtlas\Reconstruction;

use MigrationAtlas\Diagnostics\DiagnosticCollection;
use MigrationAtlas\History\HistoryEvent;

/**
 * The pure result of replaying analyzed migrations. Carries the final
 * partition-aware registry, the ordered event log, the lineage-to-events
 * association, the uncertainty ledger, replay and carried diagnostics, and
 * the reconciled counters.
 */
final readonly class ReconstructionResult
{
    /**
     * @param  list<HistoryEvent>  $events
     * @param  array<int, list<int>>  $lineageEvents
     */
    public function __construct(
        public TableRegistry $registry,
        public array $events,
        public array $lineageEvents,
        public UncertaintyLedger $ledger,
        public DiagnosticCollection $diagnostics,
        public int $migrationsScanned,
        public int $migrationsAnalyzed,
        public int $migrationsSkipped,
        public int $operationsApplied,
    ) {}

    /**
     * The events that touched one lineage, in replay order.
     *
     * @return list<HistoryEvent>
     */
    public function lineageEvents(int $lineageId): array
    {
        $sequences = array_unique($this->lineageEvents[$lineageId] ?? []);
        $events = [];

        foreach ($this->events as $event) {
            if (in_array($event->sequence, $sequences, true)) {
                $events[] = $event;
            }
        }

        return $events;
    }
}
