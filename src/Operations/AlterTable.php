<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

final readonly class AlterTable extends TableOperation
{
    /** @param list<Operation> $operations */
    public function __construct(
        SourceLocation $source,
        ExecutionCertainty $execution,
        string $table,
        string $connection,
        public array $operations,
    ) {
        parent::__construct($source, $execution, $table, $connection, 'alter_table');
    }

    public function describe(): string
    {
        return "Alter table {$this->table}";
    }
}
