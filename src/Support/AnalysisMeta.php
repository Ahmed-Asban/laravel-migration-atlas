<?php

declare(strict_types=1);

namespace MigrationAtlas\Support;

use MigrationAtlas\Reconstruction\LedgerEntry;
use MigrationAtlas\Reconstruction\State\TableStatus;

/**
 * The shared analysis envelope: command identity, effective completeness,
 * the always-present global uncertainty rollup, and the reconciled
 * counters.
 */
final readonly class AnalysisMeta
{
    /**
     * @param  list<LedgerEntry>  $uncertaintySources
     */
    public function __construct(
        public string $command,
        public string $table,
        public string $connection,
        public TableStatus $status,
        public bool $globalUncertainty,
        public int $unattributableOperations,
        public int $skippedMigrations,
        public array $uncertaintySources,
        public int $migrationsScanned,
        public int $migrationsAnalyzed,
        public int $migrationsSkipped,
        public int $operationsApplied,
    ) {}
}
