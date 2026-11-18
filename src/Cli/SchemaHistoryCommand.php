<?php

declare(strict_types=1);

namespace MigrationAtlas\Cli;

use InvalidArgumentException;
use MigrationAtlas\Atlas;
use MigrationAtlas\Discovery\DiscoveryFailed;

final class SchemaHistoryCommand extends SchemaCommand
{
    protected $signature = 'schema:history
        {table : Table name whose migration history to list}
        {--json : Emit the machine-readable analysis envelope}
        {--connection=default : Connection partition to inspect}
        {--path=* : Override migration paths (repeatable)}
        {--strict : Exit 2 when the resolved result is incomplete}';

    protected $description = 'List the migration history of a table without reading any database';

    public function handle(Atlas $atlas): int
    {
        $table = $this->argument('table');

        if (! is_string($table) || $table === '') {
            $this->error('A table name is required.');

            return ExitCode::Failure->value;
        }

        try {
            $result = $atlas->history($table, $this->discoveryOptions(), $this->connection());
        } catch (DiscoveryFailed|InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return ExitCode::Failure->value;
        }

        return $this->render($result);
    }
}
