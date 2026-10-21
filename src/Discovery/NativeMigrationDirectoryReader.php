<?php

declare(strict_types=1);

namespace MigrationAtlas\Discovery;

final class NativeMigrationDirectoryReader implements MigrationDirectoryReader
{
    public function files(string $directory): array
    {
        if (! is_dir($directory) || ! is_readable($directory)) {
            throw new DiscoveryFailed($directory);
        }

        // scandir exposes enumeration failure; a glob can silently return [].
        $entries = @scandir($directory, SCANDIR_SORT_NONE);

        if ($entries === false) {
            throw new DiscoveryFailed($directory);
        }

        $files = [];

        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..' && ! is_dir($directory.DIRECTORY_SEPARATOR.$entry)) {
                // Do not require readability/existence here. A discovered source
                // disappearing before parsing must retain its ordinal in M2.
                $files[] = $entry;
            }
        }

        return $files;
    }
}
