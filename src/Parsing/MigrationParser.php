<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

use MigrationAtlas\Discovery\MigrationFile;

interface MigrationParser
{
    public function parse(MigrationFile $file): ParsedMigration;
}
