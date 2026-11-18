<?php

declare(strict_types=1);

namespace MigrationAtlas\History;

/**
 * The outcome of resolving one requested table name in a partition.
 */
enum TableResolutionKind: string
{
    case Resolved = 'resolved';

    case Ambiguous = 'ambiguous';

    case Tombstone = 'tombstone';

    case NotFound = 'not_found';
}
