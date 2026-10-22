<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

abstract readonly class TableOperation extends Operation
{
    public function __construct(
        SourceLocation $source,
        ExecutionCertainty $execution,
        public string $table,
        public string $connection,
        string $kind,
    ) {
        parent::__construct($source, $execution, $kind);
    }
}
