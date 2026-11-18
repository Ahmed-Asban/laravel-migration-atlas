<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

final readonly class ForeignKeyDefinitionDto
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $referencedColumns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public string $referencedTable,
        public array $referencedColumns,
        public SourceLocation $source,
        public ?string $onDelete = null,
        public ?string $onUpdate = null,
        public Fidelity $fidelity = Fidelity::Full,
    ) {}
}
