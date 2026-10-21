<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

final readonly class RawMethodCall
{
    /** @param list<RawArgument> $arguments */
    public function __construct(
        public ?string $method,
        public array $arguments,
        public ?int $line,
        public string $source,
    ) {}
}
