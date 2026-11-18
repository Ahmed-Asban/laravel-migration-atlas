<?php

declare(strict_types=1);

namespace MigrationAtlas\Support;

/**
 * The projected status of one command's data payload: complete for a
 * resolved present identity, incomplete whenever applicable uncertainty
 * exists, and dropped only for certain absence under complete analysis.
 */
enum ResultStatus: string
{
    case Complete = 'complete';

    case Incomplete = 'incomplete';

    case Dropped = 'dropped';
}
