<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

abstract readonly class Operation
{
    public function __construct(
        public SourceLocation $source,
        public ExecutionCertainty $execution,
        public string $kind,
    ) {}

    abstract public function describe(): string;
}
