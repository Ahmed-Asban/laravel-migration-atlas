<?php

declare(strict_types=1);

namespace MigrationAtlas\History;

use MigrationAtlas\Reconstruction\ReconstructionResult;
use MigrationAtlas\Reconstruction\State\ColumnState;
use MigrationAtlas\Reconstruction\State\TableState;

/**
 * Resolves one column identity through its rename chain inside a resolved
 * table lineage, in both directions: a former name resolves forward to the
 * current name, and tracing by the current name still shows the original
 * add. A certain drop terminates the chain with provenance; possible drops
 * and renames keep candidates and conditional reasons.
 */
final class ColumnTraceResolver
{
    /**
     * Trace one column of a resolved lineage. Returns null when no column
     * identity in the lineage matches the requested name.
     */
    public function trace(ReconstructionResult $result, TableState $lineage, string $column): ?ColumnTrace
    {
        $state = $this->column($lineage, $column);

        if ($state === null) {
            return null;
        }

        $events = $result->lineageEvents($lineage->lineageId);

        // First pass: extend the identity chain through renames in both
        // directions — a former name resolves forward, and tracing by the
        // current name retroactively includes its renamed source.
        $identities = [$column];

        foreach ($events as $event) {
            if ($event->operation === 'rename_column' && count($event->columns) === 2) {
                [$from, $to] = $event->columns;

                if (in_array($from, $identities, true) && ! in_array($to, $identities, true)) {
                    $identities[] = $to;
                } elseif (in_array($to, $identities, true) && ! in_array($from, $identities, true)) {
                    $identities[] = $from;
                }
            }
        }

        // Second pass: collect every event touching the full chain, so
        // tracing by the current name still shows the original add.
        $chainEvents = [];

        foreach ($events as $event) {
            if ($event->columns !== [] && array_intersect($event->columns, $identities) !== []) {
                $chainEvents[] = $event;
            }
        }

        // The chain's current identity is whichever chain name the lineage
        // still holds as a current column name.
        $current = $column;

        foreach ($identities as $identity) {
            if (isset($lineage->columns[$identity])) {
                $current = $identity;
            }
        }

        $holder = $this->column($lineage, $current) ?? $state;

        return new ColumnTrace($holder, $current, $identities, $chainEvents);
    }

    /**
     * The column state holding the requested identity: current, former, or
     * statically observed possible name.
     */
    private function column(TableState $lineage, string $column): ?ColumnState
    {
        foreach ($lineage->columns as $state) {
            if ($state->name === $column
                || in_array($column, $state->formerNames, true)
                || in_array($column, $state->possibleNames, true)) {
                return $state;
            }
        }

        return null;
    }
}
