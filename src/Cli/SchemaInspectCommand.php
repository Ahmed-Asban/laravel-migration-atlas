<?php

declare(strict_types=1);

namespace MigrationAtlas\Cli;

final class SchemaInspectCommand extends SchemaCommand
{
    protected $signature = 'schema:inspect
        {table : Table name to inspect}
        {--json : Request JSON output (not implemented yet)}
        {--connection=default : Connection partition to inspect}
        {--path=* : Override migration paths}
        {--strict : Fail on incomplete analysis (not implemented yet)}';

    protected $description = 'Inspect an expected table schema (M1 discovery only)';
}
