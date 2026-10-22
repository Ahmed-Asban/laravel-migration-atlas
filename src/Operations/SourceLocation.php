<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

final readonly class SourceLocation
{
    /** @var array{int, int} */
    public array $position;

    public function __construct(
        public string $file,
        public string $migration,
        public ?int $line,
        int $migrationOrdinal,
        int $operationOrdinal,
    ) {
        $this->position = [$migrationOrdinal, $operationOrdinal];
    }
}
