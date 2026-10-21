<?php

declare(strict_types=1);

namespace MigrationAtlas\Discovery;

final readonly class MigrationFile
{
    public function __construct(
        public string $absolutePath,
        public string $name,
        public ?string $date,
        public int $ordinal,
    ) {}
}
