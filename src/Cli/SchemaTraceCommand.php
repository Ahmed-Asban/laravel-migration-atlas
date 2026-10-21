<?php

declare(strict_types=1);

namespace MigrationAtlas\Cli;

final class SchemaTraceCommand extends SchemaCommand
{
    protected $signature = 'schema:trace
        {column : Column identity in table.column notation}
        {--json : Request JSON output (not implemented yet)}
        {--connection=default : Connection partition to inspect}
        {--path=* : Override migration paths}
        {--strict : Fail on incomplete analysis (not implemented yet)}';

    protected $description = 'Trace a column through migrations (M1 discovery only)';
}
