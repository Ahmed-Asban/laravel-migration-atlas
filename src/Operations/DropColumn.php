<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

final readonly class DropColumn extends TableOperation
{
    /** @param list<string> $columns */
    public function __construct(
        SourceLocation $source,
        ExecutionCertainty $execution,
        string $table,
        string $connection,
        public array $columns,
    ) {
        parent::__construct($source, $execution, $table, $connection, 'drop_column');
    }

    public function describe(): string
    {
        return 'Drop columns '.implode(', ', $this->columns).' from '.$this->table;
    }
}
