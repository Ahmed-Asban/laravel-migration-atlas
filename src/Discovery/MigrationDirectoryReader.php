<?php

declare(strict_types=1);

namespace MigrationAtlas\Discovery;

interface MigrationDirectoryReader
{
    /**
     * Direct file names only; no recursion or source reads.
     *
     * @return list<string>
     *
     * @throws DiscoveryFailed
     */
    public function files(string $directory): array;
}
