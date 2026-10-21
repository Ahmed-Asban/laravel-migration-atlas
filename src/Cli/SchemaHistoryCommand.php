<?php

declare(strict_types=1);

namespace MigrationAtlas\Cli;

final class SchemaHistoryCommand extends SchemaCommand
{
    protected $signature = 'schema:history
        {table : Table name to show history for}
        {--json : Request JSON output (not implemented yet)}
        {--connection=default : Connection partition to inspect}
        {--path=* : Override migration paths}
        {--strict : Fail on incomplete analysis (not implemented yet)}';

    protected $description = 'Show a table migration history (M1 discovery only)';
}
