<?php

declare(strict_types=1);

namespace MigrationAtlas;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use MigrationAtlas\Cli\SchemaHistoryCommand;
use MigrationAtlas\Cli\SchemaInspectCommand;
use MigrationAtlas\Cli\SchemaTraceCommand;
use MigrationAtlas\Discovery\FilesystemMigrationFinder;
use MigrationAtlas\Discovery\MigrationDirectoryReader;
use MigrationAtlas\Discovery\MigrationFinder;
use MigrationAtlas\Discovery\NativeMigrationDirectoryReader;
use MigrationAtlas\Normalization\OperationNormalizer;
use MigrationAtlas\Normalization\SupportMatrix;
use MigrationAtlas\Parsing\MigrationParser;
use MigrationAtlas\Parsing\MigrationSourceReader;
use MigrationAtlas\Parsing\NativeMigrationSourceReader;
use MigrationAtlas\Parsing\PhpParserMigrationParser;

final class MigrationAtlasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/migration-atlas.php', 'migration-atlas');

        $this->app->bind(MigrationDirectoryReader::class, NativeMigrationDirectoryReader::class);
        $this->app->bind(MigrationFinder::class, FilesystemMigrationFinder::class);
        $this->app->bind(MigrationSourceReader::class, NativeMigrationSourceReader::class);
        $this->app->bind(SupportMatrix::class);
        $this->app->bind(OperationNormalizer::class);
        $this->app->bind(MigrationParser::class, function (): MigrationParser {
            $maxFileSize = config('migration-atlas.max_file_size');

            if (! is_int($maxFileSize)) {
                throw new InvalidArgumentException('The maximum migration file size must be an integer.');
            }

            return new PhpParserMigrationParser($maxFileSize, sources: $this->app->make(MigrationSourceReader::class));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/migration-atlas.php' => config_path('migration-atlas.php'),
            ], 'migration-atlas-config');

            $this->commands([
                SchemaInspectCommand::class,
                SchemaHistoryCommand::class,
                SchemaTraceCommand::class,
            ]);
        }
    }
}
