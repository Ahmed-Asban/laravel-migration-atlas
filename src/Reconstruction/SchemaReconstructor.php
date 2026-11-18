<?php

declare(strict_types=1);

namespace MigrationAtlas\Reconstruction;

use MigrationAtlas\Normalization\NormalizedMigration;

/**
 * Pure replay of normalized migrations into a partition-aware table
 * registry with an ordered event log and an uncertainty ledger.
 */
interface SchemaReconstructor
{
    /**
     * Replays migrations in the given discovery order. The input list
     * carries the per-migration metadata (dates, parsed flags, counters)
     * that a flat operation stream cannot represent.
     *
     * @param  list<NormalizedMigration>  $migrations
     */
    public function replay(array $migrations): ReconstructionResult;
}
