<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

final readonly class DropIndex extends TableOperation
{
    /**
     * A null name records a conventional drop without a statically derivable
     * index name, such as dropPrimary() without arguments or columns.
     *
     * @param  list<string>  $columns
     */
    public function __construct(
        SourceLocation $source,
        ExecutionCertainty $execution,
        string $table,
        string $connection,
        public string $type,
        public ?string $name,
        public array $columns = [],
    ) {
        parent::__construct($source, $execution, $table, $connection, 'drop_index');
    }

    public function describe(): string
    {
        return 'Drop '.$this->type.' index '.($this->name ?? "on {$this->table}");
    }
}
