<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;

final readonly class MigrationContext
{
    public function __construct(
        public Class_ $class,
        public ?ClassMethod $up,
        public bool $conditional,
        public ?string $connection,
    ) {}
}
