<?php

declare(strict_types=1);

namespace MigrationAtlas\Reconstruction\State;

use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Operations\ForeignKeyDefinitionDto;
use MigrationAtlas\Operations\SourceLocation;

/**
 * The replayed state of one foreign key inside a lineage.
 */
final readonly class ForeignKeyState
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
        public ?string $onDelete,
        public ?string $onUpdate,
        public Fidelity $fidelity,
        public SourceLocation $source,
    ) {}

    public static function fromDefinition(ForeignKeyDefinitionDto $key): self
    {
        return new self($key->name, $key->columns, $key->referencedTable, $key->referencedColumns,
            $key->onDelete, $key->onUpdate, $key->fidelity, $key->source);
    }
}
