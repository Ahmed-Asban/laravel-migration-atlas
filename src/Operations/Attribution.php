<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

enum Attribution: string
{
    case Attributed = 'attributed';
    case NameScoped = 'name_scoped';
    case Unattributable = 'unattributable';
}
