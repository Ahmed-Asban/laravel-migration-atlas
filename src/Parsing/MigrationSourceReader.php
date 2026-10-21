<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

use MigrationAtlas\Discovery\MigrationFile;

interface MigrationSourceReader
{
    /** @throws SourceReadFailure */
    public function read(MigrationFile $file, int $maxFileSize): string;
}
