<?php

declare(strict_types=1);

namespace MigrationAtlas\Reconstruction\State;

/**
 * The statically determined presence of one identity. ABSENT is a certain
 * tombstone; UNKNOWN is an honestly undecidable candidate.
 */
enum Existence: string
{
    case Present = 'present';

    case Absent = 'absent';

    case Unknown = 'unknown';
}
