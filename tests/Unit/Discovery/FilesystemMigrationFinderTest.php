<?php

declare(strict_types=1);

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Diagnostics\DiagnosticSeverity;
use MigrationAtlas\Discovery\DiscoveryFailed;
use MigrationAtlas\Discovery\DiscoveryOptions;
use MigrationAtlas\Discovery\FilesystemMigrationFinder;
use MigrationAtlas\Discovery\MigrationDirectoryReader;
use MigrationAtlas\Tests\Support\DiscoveryWorkspace;

beforeEach(function () {
    $this->workspace = new DiscoveryWorkspace;
});

afterEach(function () {
    $this->workspace->cleanup();
});

it('sorts by basename across directories and keeps only the last configured duplicate', function () {
    $early = '2025_01_01_000000_create_users';
    $late = '2026_01_01_000000_add_email';
    $first = $this->workspace->directory('a first path');
    $last = $this->workspace->directory('z last path');
    $this->workspace->migration('a first path/'.$late.'.php');
    $this->workspace->migration('a first path/'.$early.'.php');
    $winner = $this->workspace->migration('z last path/'.$early.'.php');

    $finder = new FilesystemMigrationFinder;
    $result = $finder->find(new DiscoveryOptions([$first, $last], $this->workspace->root));

    expect(array_column($result->files, 'name'))->toBe([$early, $late])
        ->and(array_column($result->files, 'ordinal'))->toBe([1, 2])
        ->and(realpath($result->files[0]->absolutePath))->toBe(realpath($winner))
        ->and($result)->toHaveCount(2)
        ->and(iterator_to_array($result))->toBe($result->files)
        ->and($result->diagnostics)->toHaveCount(0);

    $reversed = $finder->find(new DiscoveryOptions([$last, $first], $this->workspace->root));

    expect(realpath($reversed->files[0]->absolutePath))
        ->toBe(realpath($first.'/'.$early.'.php'));
});

it('matches the non-recursive migration pattern and treats configured directories literally', function () {
    $directory = $this->workspace->directory('migrations [custom]');
    $this->workspace->migration('migrations [custom]/2026_01_01_000000_create_users.php');
    $this->workspace->migration('migrations [custom]/legacy_migration.php');
    $this->workspace->migration('migrations [custom]/plain.php');
    $this->workspace->migration('migrations [custom]/2026_01_02_000000_ignored.txt');
    $this->workspace->migration('migrations [custom]/2026_01_03_000000_ignored.PHP');
    $this->workspace->migration('migrations [custom]/.hidden_migration.php');
    $this->workspace->migration('migrations [custom]/nested/2026_01_04_000000_nested.php');
    $this->workspace->directory('migrations [custom]/2026_01_05_000000_directory.php');

    $result = (new FilesystemMigrationFinder)->find(new DiscoveryOptions([$directory], $this->workspace->root));

    expect(array_column($result->files, 'name'))->toBe(['2026_01_01_000000_create_users', 'legacy_migration'])
        ->and($result->diagnostics)->toHaveCount(1);
});

it('accepts valid calendar timestamps without timezone conversion', function (string $name, string $date) {
    $this->workspace->migration('migrations/'.$name.'.php');

    $result = (new FilesystemMigrationFinder)->find(new DiscoveryOptions(['migrations'], $this->workspace->root));

    expect($result->files[0]->date)->toBe($date)
        ->and($result->diagnostics)->toHaveCount(0);
})->with([
    ['0001_01_01_000000_create_users', '0001-01-01 00:00:00'],
    ['2024_02_29_235959_create_users', '2024-02-29 23:59:59'],
    ['2026_10_21_123456_create_users', '2026-10-21 12:34:56'],
]);

it('keeps nonstandard migrations with unknown dates and source-located warnings', function (string $name) {
    $source = $this->workspace->migration('migrations/'.$name.'.php');

    $result = (new FilesystemMigrationFinder)->find(new DiscoveryOptions(['migrations'], $this->workspace->root));
    $diagnostic = $result->diagnostics->items[0];

    expect($result)->toHaveCount(1)
        ->and($result->files[0]->date)->toBeNull()
        ->and($result->files[0]->ordinal)->toBe(1)
        ->and($result->diagnostics)->toHaveCount(1)
        ->and(iterator_to_array($result->diagnostics))->toBe([$diagnostic])
        ->and($diagnostic->code)->toBe(DiagnosticCode::NonStandardFilename)
        ->and($diagnostic->severity)->toBe(DiagnosticSeverity::Warning)
        ->and(realpath($diagnostic->file))->toBe(realpath($source))
        ->and($diagnostic->line)->toBeNull();
})->with([
    'missing timestamp' => ['legacy_migration'],
    'invalid calendar date' => ['2026_02_29_000000_create_users'],
    'invalid month' => ['2026_13_01_000000_create_users'],
    'invalid day' => ['2026_01_00_000000_create_users'],
    'invalid hour' => ['2026_01_01_240000_create_users'],
    'invalid minute' => ['2026_01_01_006000_create_users'],
    'invalid second' => ['2026_01_01_000060_create_users'],
    'missing description' => ['2026_01_01_000000_'],
]);

it('returns an empty list for empty directories or no configured paths', function () {
    $directory = $this->workspace->directory('empty');
    $finder = new FilesystemMigrationFinder;

    expect($finder->find(new DiscoveryOptions([$directory], $this->workspace->root)))->toHaveCount(0)
        ->and($finder->find(new DiscoveryOptions([], $this->workspace->root)))->toHaveCount(0);
});

it('preserves literal trailing backslashes in Unix directory names', function () {
    $base = $this->workspace->directory('project\\');
    $source = $this->workspace->migration('project\\/migrations\\/2026_01_01_000000_create_users.php');
    $options = new DiscoveryOptions(['migrations\\'], $base);
    $result = (new FilesystemMigrationFinder)->find($options);

    expect($options->paths)->toBe([$base.'/migrations\\'])
        ->and($result)->toHaveCount(1)
        ->and($result->files[0]->absolutePath)->toBe($source);
})->skip(DIRECTORY_SEPARATOR === '\\', 'Backslashes are separators on Windows.');

it('reports invalid discovery paths instead of returning an empty or partial list', function () {
    $directory = $this->workspace->directory('valid');
    $file = $this->workspace->migration('valid/2026_01_01_000000_create_users.php');
    $finder = new FilesystemMigrationFinder;

    foreach ([$this->workspace->root.'/missing', $file] as $invalid) {
        expect(fn () => $finder->find(new DiscoveryOptions([$directory, $invalid], $this->workspace->root)))
            ->toThrow(DiscoveryFailed::class, 'Cannot enumerate migration directory: '.$invalid);
    }
});

it('propagates an injected unreadable-directory failure on every operating system', function () {
    $directory = $this->workspace->root.'/unreadable';
    $reader = Mockery::mock(MigrationDirectoryReader::class);
    $reader->shouldReceive('files')->once()->with($directory)->andThrow(new DiscoveryFailed($directory));

    expect(fn () => (new FilesystemMigrationFinder($reader))->find(new DiscoveryOptions([$directory], $this->workspace->root)))
        ->toThrow(DiscoveryFailed::class, 'Cannot enumerate migration directory: '.$directory);

    Mockery::close();
});

it('produces byte-identical file metadata and diagnostics for shuffled directory listings', function () {
    $paths = [$this->workspace->root.'/z', $this->workspace->root.'/a'];
    $listings = [
        ['2026_01_02_000000_second.php', 'legacy_migration.php', '2026_01_01_000000_first.php'],
        ['2026_01_03_000000_third.php', 'legacy_migration.php', '2026_01_01_000000_first.php'],
    ];
    $snapshots = [];

    for ($run = 0; $run < 12; $run++) {
        $reader = Mockery::mock(MigrationDirectoryReader::class);

        foreach ($paths as $index => $path) {
            $names = $listings[$index];
            shuffle($names);
            $reader->shouldReceive('files')->once()->with($path)->andReturn($names);
        }

        $result = (new FilesystemMigrationFinder($reader))->find(new DiscoveryOptions($paths, $this->workspace->root));
        $snapshots[] = json_encode($result, JSON_THROW_ON_ERROR);

        expect(array_column($result->files, 'ordinal'))->toBe([1, 2, 3, 4])
            ->and($result->diagnostics)->toHaveCount(1)
            ->and($result->diagnostics->items[0]->file)->toBe($paths[1].DIRECTORY_SEPARATOR.'legacy_migration.php');

        Mockery::close();
    }

    expect(array_unique($snapshots))->toHaveCount(1);
});

it('does not execute migration source and preserves discovered entries after source disappearance', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_side_effect.php', '<?php $GLOBALS["migration_atlas_m1_executed"] = true; throw new RuntimeException;');
    $this->workspace->migration('migrations/2026_01_02_000000_invalid.php', '<?php this is invalid PHP !!!');
    $result = (new FilesystemMigrationFinder)->find(new DiscoveryOptions(['migrations'], $this->workspace->root));
    unlink($result->files[0]->absolutePath);

    expect($GLOBALS)->not->toHaveKey('migration_atlas_m1_executed')
        ->and($result)->toHaveCount(2)
        ->and(array_column($result->files, 'ordinal'))->toBe([1, 2])
        ->and($result->files[0]->name)->toBe('2026_01_01_000000_side_effect');
});
