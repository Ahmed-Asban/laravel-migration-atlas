<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

final readonly class RenameColumn extends TableOperation
{
    public function __construct(
        SourceLocation $source,
        ExecutionCertainty $execution,
        string $table,
        string $connection,
        public string $from,
        public string $to,
    ) {
        parent::__construct($source, $execution, $table, $connection, 'rename_column');
    }

    public function describe(): string
    {
        return "Rename column {$this->table}.{$this->from} to {$this->to}";
    }
}
