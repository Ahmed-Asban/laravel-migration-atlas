<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

final readonly class ColumnDefinitionDto
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public string $name,
        public string $type,
        public SourceLocation $source,
        public bool $nullable = false,
        public bool $hasDefault = false,
        public mixed $default = null,
        public array $attributes = [],
        public Fidelity $fidelity = Fidelity::Full,
    ) {}
}
