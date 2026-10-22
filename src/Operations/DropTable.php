<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

final readonly class DropTable extends TableOperation
{
    public function __construct(
        SourceLocation $source,
        ExecutionCertainty $execution,
        string $table,
        string $connection,
        public bool $ifExists = false,
    ) {
        parent::__construct($source, $execution, $table, $connection, 'drop_table');
    }

    public function describe(): string
    {
        return 'Drop table '.$this->table.($this->ifExists ? ' if it exists' : '');
    }
}
