<?php

declare(strict_types=1);

namespace MigrationAtlas\Cli;

use InvalidArgumentException;
use MigrationAtlas\Atlas;
use MigrationAtlas\Discovery\DiscoveryFailed;

final class SchemaTraceCommand extends SchemaCommand
{
    protected $signature = 'schema:trace
        {column : Column identity in table.column notation (a former name resolves forward)}
        {--json : Emit the machine-readable analysis envelope}
        {--connection=default : Connection partition to inspect}
        {--path=* : Override migration paths (repeatable)}
        {--strict : Exit 2 when the resolved result is incomplete}';

    protected $description = 'Trace a column through migrations without reading any database';

    public function handle(Atlas $atlas): int
    {
        $column = $this->argument('column');

        if (! is_string($column) || ! str_contains($column, '.') || substr_count($column, '.') !== 1) {
            $this->error('A column identity in table.column notation is required, for example users.email.');

            return ExitCode::Failure->value;
        }

        [$table, $name] = explode('.', $column, 2);

        if ($table === '' || $name === '') {
            $this->error('A column identity in table.column notation is required, for example users.email.');

            return ExitCode::Failure->value;
        }

        try {
            $result = $atlas->trace($table, $name, $this->discoveryOptions(), $this->connection());
        } catch (DiscoveryFailed|InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return ExitCode::Failure->value;
        }

        return $this->render($result);
    }
}
