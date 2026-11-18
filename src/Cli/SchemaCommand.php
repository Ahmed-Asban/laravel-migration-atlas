<?php

declare(strict_types=1);

namespace MigrationAtlas\Cli;

use Illuminate\Console\Command;
use InvalidArgumentException;
use MigrationAtlas\Cli\Render\JsonRenderer;
use MigrationAtlas\Cli\Render\Renderer;
use MigrationAtlas\Cli\Render\TextRenderer;
use MigrationAtlas\Discovery\DiscoveryFailed;
use MigrationAtlas\Discovery\DiscoveryOptions;
use MigrationAtlas\Support\AnalysisResult;
use Symfony\Component\Console\Output\OutputInterface;

abstract class SchemaCommand extends Command
{
    /**
     * Build the discovery options from repeatable overrides or the
     * configured paths. Path-configuration and discovery failures are
     * command errors.
     *
     * @throws DiscoveryFailed
     * @throws InvalidArgumentException
     */
    protected function discoveryOptions(): DiscoveryOptions
    {
        $paths = $this->option('path');

        if (! is_array($paths) || $paths === []) {
            $paths = config('migration-atlas.paths', []);
        }

        if (! is_array($paths)) {
            throw new InvalidArgumentException('Migration paths configuration must be an array of local directories.');
        }

        return new DiscoveryOptions($paths, base_path());
    }

    protected function connection(): string
    {
        $connection = $this->option('connection');

        return is_string($connection) && $connection !== '' ? $connection : 'default';
    }

    /**
     * Render one result through the selected renderer and return its exit
     * code. Errors take precedence over strict incompleteness.
     */
    protected function render(AnalysisResult $result): int
    {
        $renderer = $this->option('json') ? new JsonRenderer : new TextRenderer;

        return $renderer->render($this->output(), $result, (bool) $this->option('strict'));
    }

    protected function output(): OutputInterface
    {
        return $this->getOutput();
    }
}
