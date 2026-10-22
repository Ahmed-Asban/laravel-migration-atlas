<?php

declare(strict_types=1);

namespace MigrationAtlas\Normalization;

use MigrationAtlas\Diagnostics\DiagnosticCollection;
use MigrationAtlas\Discovery\MigrationFile;
use MigrationAtlas\Operations\Operation;

final readonly class NormalizedMigration
{
    /** @param list<Operation> $operations */
    public function __construct(
        public MigrationFile $file,
        public array $operations,
        public DiagnosticCollection $diagnostics,
        public bool $parsed,
    ) {}
}
