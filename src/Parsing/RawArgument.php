<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

final readonly class RawArgument
{
    public function __construct(
        public mixed $value,
        public ?string $name,
        public bool $unpack,
        public ?int $line,
        public string $source,
    ) {}
}
