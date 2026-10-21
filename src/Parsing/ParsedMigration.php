<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

use MigrationAtlas\Diagnostics\DiagnosticCollection;
use MigrationAtlas\Discovery\MigrationFile;

final readonly class ParsedMigration
{
    /** @param list<RawSchemaCall> $calls */
    public function __construct(
        public MigrationFile $file,
        public array $calls,
        public bool $conditional,
        public ?string $connection,
        public DiagnosticCollection $diagnostics,
        public bool $parsed,
    ) {}
}
