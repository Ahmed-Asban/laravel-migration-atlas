<?php

declare(strict_types=1);

namespace MigrationAtlas\Discovery;

/**
 * The schema dumps Laravel's `schema:dump` writes under `database/schema`.
 * Dump contents are never parsed; detection only exists so results stay
 * honest about the unanalyzed baseline they replay on top of.
 */
final readonly class SchemaDump
{
    public function __construct(
        public string $file,
        public string $connection,
    ) {}
}
