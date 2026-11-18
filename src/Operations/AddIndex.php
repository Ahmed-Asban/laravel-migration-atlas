<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

final readonly class AddIndex extends TableOperation
{
    public function __construct(
        SourceLocation $source,
        ExecutionCertainty $execution,
        string $table,
        string $connection,
        public IndexDefinitionDto $index,
    ) {
        parent::__construct($source, $execution, $table, $connection, 'add_index');
    }

    public function describe(): string
    {
        return "Add {$this->index->type} index {$this->index->name} on {$this->table}";
    }
}
