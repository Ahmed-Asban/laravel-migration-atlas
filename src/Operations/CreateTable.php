<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

final readonly class CreateTable extends TableOperation
{
    /**
     * The body is ordered. Columns are a convenient projection of its AddColumn
     * operations, not a second stream to replay.
     *
     * @param  list<ColumnDefinitionDto>  $columns
     * @param  list<Operation>  $operations
     */
    public function __construct(
        SourceLocation $source,
        ExecutionCertainty $execution,
        string $table,
        string $connection,
        public array $columns,
        public array $operations,
    ) {
        parent::__construct($source, $execution, $table, $connection, 'create_table');
    }

    public function describe(): string
    {
        return "Create table {$this->table}";
    }
}
