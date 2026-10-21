<?php

declare(strict_types=1);

namespace MigrationAtlas;

use Illuminate\Support\ServiceProvider;
use MigrationAtlas\Cli\SchemaHistoryCommand;
use MigrationAtlas\Cli\SchemaInspectCommand;
use MigrationAtlas\Cli\SchemaTraceCommand;
use MigrationAtlas\Discovery\FilesystemMigrationFinder;
use MigrationAtlas\Discovery\MigrationDirectoryReader;
use MigrationAtlas\Discovery\MigrationFinder;
use MigrationAtlas\Discovery\NativeMigrationDirectoryReader;

final class MigrationAtlasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/migration-atlas.php', 'migration-atlas');

        $this->app->bind(MigrationDirectoryReader::class, NativeMigrationDirectoryReader::class);
        $this->app->bind(MigrationFinder::class, FilesystemMigrationFinder::class);
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
