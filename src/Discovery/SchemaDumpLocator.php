<?php

declare(strict_types=1);

namespace MigrationAtlas\Discovery;

/**
 * Locates schema dumps per connection under a project's
 * `database/schema` directory. Read-only: the dump contents are never
 * read or interpreted, only the file names are observed.
 */
final class SchemaDumpLocator
{
    public function locate(string $projectRoot): SchemaDumpList
    {
        $directory = rtrim($projectRoot, '/\\').'/database/schema';

        if (! is_dir($directory)) {
            return new SchemaDumpList([]);
        }

        $found = @scandir($directory);

        if ($found === false) {
            return new SchemaDumpList([]);
        }

        $dumps = [];

        foreach ($found as $entry) {
            if (! str_ends_with($entry, '-schema.sql')) {
                continue;
            }

            $connection = substr($entry, 0, -strlen('-schema.sql'));
            $dumps[] = new SchemaDump('database/schema/'.$entry, $connection);
        }

        return new SchemaDumpList($dumps);
    }
}
