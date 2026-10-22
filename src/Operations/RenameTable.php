<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

final readonly class RenameTable extends Operation
{
    public function __construct(
        SourceLocation $source,
        ExecutionCertainty $execution,
        public string $from,
        public string $to,
        public string $connection,
    ) {
        parent::__construct($source, $execution, 'rename_table');
    }

    public function describe(): string
    {
        return "Rename table {$this->from} to {$this->to}";
    }
}
