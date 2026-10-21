<?php

declare(strict_types=1);

use MigrationAtlas\Discovery\DiscoveryOptions;

it('resolves relative paths against the project root and preserves path precedence', function () {
    $root = dirname(__DIR__, 3);
    $absolute = $root.'/custom migrations';

    $options = new DiscoveryOptions(['database/migrations', $absolute, 'database/migrations'], $root);

    expect($options->paths)->toBe([
        $root.DIRECTORY_SEPARATOR.'database/migrations',
        $absolute,
        $root.DIRECTORY_SEPARATOR.'database/migrations',
    ]);
});

it('allows an explicitly empty path set', function () {
    expect((new DiscoveryOptions([], dirname(__DIR__, 3)))->paths)->toBe([]);
});

it('rejects invalid or nonlocal paths before filesystem access', function (mixed $path) {
    expect(fn () => new DiscoveryOptions([$path], dirname(__DIR__, 3)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'whitespace' => ['  '],
    'null byte' => ["database/\0migrations"],
    'remote' => ['https://example.com/migrations'],
    'stream wrapper' => ['php://filter/resource=migrations'],
    'non-string' => [123],
    'null' => [null],
]);

it('requires an absolute local project root', function () {
    expect(fn () => new DiscoveryOptions(['migrations'], 'relative/root'))
        ->toThrow(InvalidArgumentException::class);
});

it('preserves Windows drive and UNC absolute paths', function () {
    $paths = ['D:\\project\\migrations', 'D:/other/migrations', '\\\\server\\share\\migrations'];

    expect((new DiscoveryOptions($paths, 'D:\\project'))->paths)->toBe($paths);
})->skip(DIRECTORY_SEPARATOR !== '\\', 'Windows path semantics only.');

it('rejects Windows drive-relative paths', function () {
    expect(fn () => new DiscoveryOptions(['D:migrations'], 'D:\\project'))
        ->toThrow(InvalidArgumentException::class);
})->skip(DIRECTORY_SEPARATOR !== '\\', 'Windows path semantics only.');

it('rejects Windows paths rooted on an unspecified drive', function (string $path) {
    expect(fn () => new DiscoveryOptions([$path], 'D:\\project'))
        ->toThrow(InvalidArgumentException::class);
})->with(['/migrations', '\\migrations'])
    ->skip(DIRECTORY_SEPARATOR !== '\\', 'Windows path semantics only.');
