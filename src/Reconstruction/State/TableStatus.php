<?php

declare(strict_types=1);

namespace MigrationAtlas\Reconstruction\State;

/**
 * Analysis completeness of one table lineage. Status never encodes
 * existence or fidelity; those are separate axes.
 */
enum TableStatus: string
{
    case Complete = 'complete';

    case Incomplete = 'incomplete';
}
