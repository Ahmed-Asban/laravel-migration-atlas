<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

final readonly class AddForeignKey extends TableOperation
{
    public function __construct(
        SourceLocation $source,
        ExecutionCertainty $execution,
        string $table,
        string $connection,
        public ForeignKeyDefinitionDto $key,
    ) {
        parent::__construct($source, $execution, $table, $connection, 'add_foreign_key');
    }

    public function describe(): string
    {
        return "Add foreign key {$this->key->name} on {$this->table}";
    }
}
