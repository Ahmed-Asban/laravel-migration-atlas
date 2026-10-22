<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

final readonly class AddColumn extends TableOperation
{
    public function __construct(
        SourceLocation $source,
        ExecutionCertainty $execution,
        string $table,
        string $connection,
        public ColumnDefinitionDto $column,
    ) {
        parent::__construct($source, $execution, $table, $connection, 'add_column');
    }

    public function describe(): string
    {
        return "Add column {$this->table}.{$this->column->name}";
    }
}
