<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

final readonly class RawSchemaCall
{
    /**
     * @param  list<RawArgument>  $arguments
     * @param  list<RawBlueprintCall>  $blueprintCalls
     */
    public function __construct(
        public ?string $method,
        public array $arguments,
        public ?int $line,
        public string $source,
        public ?string $connection,
        public bool $connectionExplicit = false,
        public bool $unresolvedRegion = false,
        public bool $rawSql = false,
        public array $blueprintCalls = [],
        public bool $callbackResolved = true,
    ) {}
}
