<?php

declare(strict_types=1);

namespace MigrationAtlas\Reconstruction\State;

/**
 * Why an identity became incomplete: a directly attributed unresolved
 * operation, global uncertainty, conditional execution, or a replay conflict.
 */
enum IncompletenessOrigin: string
{
    case Direct = 'direct';

    case Global = 'global';

    case Conditional = 'conditional';

    case Conflict = 'conflict';
}
