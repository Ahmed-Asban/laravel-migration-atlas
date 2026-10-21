<?php

declare(strict_types=1);

namespace MigrationAtlas\Cli;

use Illuminate\Console\Command;
use InvalidArgumentException;
use MigrationAtlas\Discovery\DiscoveryFailed;
use MigrationAtlas\Discovery\DiscoveryOptions;
use MigrationAtlas\Discovery\MigrationFinder;

abstract class SchemaCommand extends Command
{
    public function handle(MigrationFinder $finder): int
    {
        try {
            $paths = $this->option('path');

            if (! is_array($paths) || $paths === []) {
                $paths = config('migration-atlas.paths', []);
            }

            if (! is_array($paths)) {
                throw new InvalidArgumentException('Migration paths configuration must be an array of local directories.');
            }

            $migrations = $finder->find(new DiscoveryOptions($paths, base_path()));
        } catch (DiscoveryFailed|InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($migrations->diagnostics as $diagnostic) {
            $this->warn($diagnostic->code->value.': '.$diagnostic->message);
        }

        $this->error('The analyzer is not implemented yet (M1 discovery only).');

        return self::FAILURE;
    }
}
