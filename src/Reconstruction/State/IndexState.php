<?php

declare(strict_types=1);

namespace MigrationAtlas\Reconstruction\State;

use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Operations\IndexDefinitionDto;
use MigrationAtlas\Operations\SourceLocation;

/**
 * The replayed state of one index inside a lineage.
 */
final readonly class IndexState
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public string $name,
        public string $type,
        public array $columns,
        public Fidelity $fidelity,
        public SourceLocation $source,
    ) {}

    public static function fromDefinition(IndexDefinitionDto $index): self
    {
        return new self($index->name, $index->type, $index->columns, $index->fidelity, $index->source);
    }
}
