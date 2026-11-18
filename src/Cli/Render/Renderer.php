<?php

declare(strict_types=1);

namespace MigrationAtlas\Cli\Render;

use MigrationAtlas\Support\AnalysisResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Turns one command result into console output and returns its exit code.
 */
interface Renderer
{
    public function render(OutputInterface $output, AnalysisResult $result, bool $strict = false): int;
}
