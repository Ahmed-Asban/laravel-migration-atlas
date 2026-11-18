<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

final readonly class DropForeignKey extends TableOperation
{
    /**
     * A null name is not expected for a static capture: dropForeign resolves a
     * conventional name even when only columns are given. The nullable shape
     * mirrors DropIndex for future guard clauses.
     *
     * @param  list<string>  $columns
     */
    public function __construct(
        SourceLocation $source,
        ExecutionCertainty $execution,
        string $table,
        string $connection,
        public ?string $name,
        public array $columns = [],
    ) {
        parent::__construct($source, $execution, $table, $connection, 'drop_foreign_key');
    }

    public function describe(): string
    {
        return 'Drop foreign key '.($this->name ?? "on {$this->table}");
    }
}
