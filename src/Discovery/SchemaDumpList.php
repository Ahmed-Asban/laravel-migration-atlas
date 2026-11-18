<?php

declare(strict_types=1);

namespace MigrationAtlas\Discovery;

/**
 * The schema dumps located for one project, ordered by connection name.
 */
final readonly class SchemaDumpList
{
    /** @param  list<SchemaDump>  $dumps */
    public function __construct(public array $dumps) {}

    public function forConnection(string $connection): ?SchemaDump
    {
        foreach ($this->dumps as $dump) {
            if ($dump->connection === $connection) {
                return $dump;
            }
        }

        return null;
    }
}
