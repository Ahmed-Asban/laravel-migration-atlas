<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

enum ExecutionCertainty: string
{
    case Always = 'always';
    case Conditional = 'conditional';
}
