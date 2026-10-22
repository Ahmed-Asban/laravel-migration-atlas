<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

enum Fidelity: string
{
    case Full = 'full';
    case Reduced = 'reduced';
}
