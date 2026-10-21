<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use MigrationAtlas\Discovery\FilesystemMigrationFinder;
use MigrationAtlas\Discovery\MigrationDirectoryReader;
use MigrationAtlas\Discovery\MigrationFinder;
use MigrationAtlas\Discovery\NativeMigrationDirectoryReader;
use MigrationAtlas\MigrationAtlasServiceProvider;

it('boots the service provider with database access blocked', function () {
    expect($this->app->getProvider(MigrationAtlasServiceProvider::class))
        ->toBeInstanceOf(MigrationAtlasServiceProvider::class)
        ->and($this->app->isBooted())->toBeTrue();
});

it('resolves the discovery interfaces without database access', function () {
    expect($this->app->make(MigrationFinder::class))->toBeInstanceOf(FilesystemMigrationFinder::class)
        ->and($this->app->make(MigrationDirectoryReader::class))->toBeInstanceOf(NativeMigrationDirectoryReader::class);
});

it('merges the approved configuration defaults', function () {
    expect(config('migration-atlas.paths'))->toBe([database_path('migrations')])
        ->and(config('migration-atlas.max_file_size'))->toBe(1024 * 1024);
});

it('preserves application overrides while supplying missing defaults', function () {
    config()->set('migration-atlas', ['max_file_size' => 2048]);

    (new MigrationAtlasServiceProvider($this->app))->register();

    expect(config('migration-atlas.max_file_size'))->toBe(2048)
        ->and(config('migration-atlas.paths'))->toBe([database_path('migrations')]);
});

it('publishes configuration under the package tag', function () {
    $paths = ServiceProvider::pathsToPublish(MigrationAtlasServiceProvider::class, 'migration-atlas-config');

    expect($paths)->toHaveCount(1);

    $source = array_key_first($paths);

    expect(realpath($source))->toBe(realpath(dirname(__DIR__, 2).'/config/migration-atlas.php'))
        ->and($paths[$source])->toBe(config_path('migration-atlas.php'));
});
