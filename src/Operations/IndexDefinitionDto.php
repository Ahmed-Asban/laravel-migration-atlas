<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

final readonly class IndexDefinitionDto
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public string $name,
        public string $type,
        public array $columns,
        public SourceLocation $source,
        public Fidelity $fidelity = Fidelity::Full,
    ) {}
}
