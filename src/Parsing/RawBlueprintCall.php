<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

final readonly class RawBlueprintCall
{
    /** @param list<RawMethodCall> $modifiers */
    public function __construct(
        public RawMethodCall $rootCall,
        public array $modifiers,
        public bool $unresolvedRegion = false,
    ) {}
}
