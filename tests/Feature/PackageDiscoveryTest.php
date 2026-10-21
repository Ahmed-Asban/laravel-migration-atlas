<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use MigrationAtlas\MigrationAtlasServiceProvider;

it('configures the agreed Composer identity and PSR-4 mapping', function () {
    $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['name'])->toBe('ahmed-asban/laravel-migration-atlas')
        ->and($composer['license'])->toBe('MIT')
        ->and($composer['autoload']['psr-4'])->toBe(['MigrationAtlas\\' => 'src/'])
        ->and($composer['extra']['laravel']['providers'])->toBe([MigrationAtlasServiceProvider::class])
        ->and($composer['require'])->not->toHaveKey('illuminate/database');
});

it('lets the Laravel package manifest discover the provider from installed metadata', function () {
    $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $base = dirname(__DIR__, 2).'/.cache/tests/package-discovery';
    $files = new Filesystem;

    $files->ensureDirectoryExists($base.'/vendor/composer');
    $files->ensureDirectoryExists($base.'/bootstrap/cache');
    $files->put($base.'/composer.json', '{}');
    $files->put($base.'/vendor/composer/installed.json', json_encode([
        'packages' => [[
            'name' => $composer['name'],
            'extra' => $composer['extra'],
        ]],
    ], JSON_THROW_ON_ERROR));

    $manifest = new PackageManifest($files, $base, $base.'/bootstrap/cache/packages.php');
    $manifest->build();

    expect($manifest->providers())->toContain(MigrationAtlasServiceProvider::class);
});
