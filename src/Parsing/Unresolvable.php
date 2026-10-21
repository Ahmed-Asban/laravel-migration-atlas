<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

final readonly class Unresolvable
{
    public function __construct(public string $expression) {}
}
