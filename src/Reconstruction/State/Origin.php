<?php

declare(strict_types=1);

namespace MigrationAtlas\Reconstruction\State;

/**
 * Where a lineage's schema state came from. UNKNOWN marks phantom tables
 * altered by analyzed source without any analyzed create.
 */
enum Origin: string
{
    case CreatedHere = 'created_here';

    case Unknown = 'unknown';
}
