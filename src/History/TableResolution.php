<?php

declare(strict_types=1);

namespace MigrationAtlas\History;

use MigrationAtlas\Reconstruction\State\TableState;

/**
 * One resolved identity for a requested table name: a single live or
 * uncertain candidate, several ambiguous candidates, a certain tombstone,
 * or nothing.
 */
final readonly class TableResolution
{
    /**
     * @param  list<TableState>  $candidates
     */
    public function __construct(
        public TableResolutionKind $kind,
        public array $candidates,
    ) {}

    public function primary(): ?TableState
    {
        return $this->candidates[0] ?? null;
    }
}
