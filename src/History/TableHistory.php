<?php

declare(strict_types=1);

namespace MigrationAtlas\History;

use MigrationAtlas\Reconstruction\ReconstructionResult;
use MigrationAtlas\Reconstruction\State\Existence;
use MigrationAtlas\Reconstruction\State\TableState;

/**
 * Resolves requested table names against the replayed registry and groups
 * event timelines by lineage. A certain live lineage wins over certain
 * tombstones; several live or uncertain candidates are ambiguous and none
 * is silently chosen.
 */
final class TableHistory
{
    public function resolve(ReconstructionResult $result, string $table, string $connection = 'default'): TableResolution
    {
        $matches = $result->registry->byName($connection, $table);
        $live = [];

        foreach ($matches as $lineage) {
            if ($lineage->existence === Existence::Present || $lineage->existence === Existence::Unknown) {
                $live[] = $lineage;
            }
        }

        if (count($live) > 1) {
            return new TableResolution(TableResolutionKind::Ambiguous, $live);
        }

        if (count($live) === 1) {
            return new TableResolution(TableResolutionKind::Resolved, $live);
        }

        $tombstones = array_values(array_filter(
            $matches,
            static fn (TableState $lineage): bool => $lineage->existence === Existence::Absent,
        ));

        if ($tombstones !== []) {
            // Only certain tombstones carry the name: the latest one wins.
            usort($tombstones, fn (TableState $a, TableState $b): int => $this->position($a) <=> $this->position($b));

            return new TableResolution(TableResolutionKind::Tombstone, [array_pop($tombstones)]);
        }

        return new TableResolution(TableResolutionKind::NotFound, []);
    }

    /**
     * Every lineage matching the requested name, ordered as history renders
     * them: earlier dead lineages by their drop order, then the primary
     * live or latest identity.
     *
     * @param  list<TableState>  $matches
     * @return list<TableState>
     */
    public function orderedLineages(array $matches, TableState $primary): array
    {
        $dead = [];
        $live = [];

        foreach ($matches as $lineage) {
            if ($lineage->lineageId === $primary->lineageId) {
                continue;
            }

            if ($lineage->existence === Existence::Absent) {
                $dead[] = $lineage;
            } else {
                $live[] = $lineage;
            }
        }

        usort($dead, static fn (TableState $a, TableState $b): int => $a->lineageId <=> $b->lineageId);

        return [...$dead, ...$live, $primary];
    }

    /**
     * The replay position of a lineage's certain drop; lineages without a
     * known drop sort last.
     *
     * @return array{int, int}
     */
    private function position(TableState $lineage): array
    {
        return $lineage->droppedBy === null ? [PHP_INT_MAX, PHP_INT_MAX] : $lineage->droppedBy->position;
    }
}
