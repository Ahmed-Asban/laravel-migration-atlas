<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use MigrationAtlas\Diagnostics\Diagnostic;
use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Diagnostics\DiagnosticCollection;
use MigrationAtlas\Diagnostics\DiagnosticSeverity;
use MigrationAtlas\Discovery\DiscoveryFailed;
use MigrationAtlas\Discovery\DiscoveryOptions;
use MigrationAtlas\Discovery\MigrationFinder;
use MigrationAtlas\Discovery\MigrationList;

it('registers the command arguments and shared options', function (string $name, string $argument) {
    $commands = Artisan::all();

    expect($commands)->toHaveKey($name);

    $definition = $commands[$name]->getDefinition();

    expect($definition->hasArgument($argument))->toBeTrue()
        ->and($definition->getArgument($argument)->isRequired())->toBeTrue()
        ->and($definition->hasOption('json'))->toBeTrue()
        ->and($definition->getOption('connection')->getDefault())->toBe('default')
        ->and($definition->getOption('path')->isArray())->toBeTrue()
        ->and($definition->hasOption('strict'))->toBeTrue();
})->with([
    ['schema:inspect', 'table'],
    ['schema:history', 'table'],
    ['schema:trace', 'column'],
]);

it('reports the unavailable analyzer without executing migrations or connecting to a database', function (string $name, string $argument, string $target) {
    $path = dirname(__DIR__).'/Fixtures/migrations';

    config()->set('migration-atlas.paths', [$path]);

    $this->artisan($name, [
        $argument => $target,
        '--json' => true,
        '--strict' => true,
        '--connection' => 'archive',
        '--path' => [$path],
    ])
        ->expectsOutput('The analyzer is not implemented yet (M1 discovery only).')
        ->assertExitCode(1);
})->with([
    ['schema:inspect', 'table', 'users'],
    ['schema:history', 'table', 'users'],
    ['schema:trace', 'column', 'users.email'],
]);

it('passes configured or overriding paths to discovery for every command', function (string $name, string $argument, string $target, bool $override) {
    config()->set('migration-atlas.paths', ['database/migrations', 'configured']);
    $absolute = dirname(__DIR__).'/Fixtures/migrations';
    $paths = $override ? ['custom', $absolute] : ['database/migrations', 'configured'];
    $expected = (new DiscoveryOptions($paths, base_path()))->paths;
    $finder = Mockery::mock(MigrationFinder::class);
    $finder->shouldReceive('find')->once()
        ->withArgs(fn (DiscoveryOptions $options) => $options->paths === $expected)
        ->andReturn(new MigrationList([]));
    $this->app->instance(MigrationFinder::class, $finder);
    $arguments = [$argument => $target];

    if ($override) {
        $arguments['--path'] = $paths;
    }

    $this->artisan($name, $arguments)
        ->expectsOutput('The analyzer is not implemented yet (M1 discovery only).')
        ->assertExitCode(1);
})->with([
    ['schema:inspect', 'table', 'users'],
    ['schema:history', 'table', 'users'],
    ['schema:trace', 'column', 'users.email'],
])->with([false, true]);

it('reports discovery failure as a command error even in strict JSON mode', function (string $name, string $argument, string $target) {
    $path = base_path('unreadable');
    $finder = Mockery::mock(MigrationFinder::class);
    $finder->shouldReceive('find')->once()->andThrow(new DiscoveryFailed($path));
    $this->app->instance(MigrationFinder::class, $finder);

    $this->artisan($name, [$argument => $target, '--path' => [$path], '--json' => true, '--strict' => true])
        ->expectsOutput('Cannot enumerate migration directory: '.$path)
        ->assertExitCode(1);
})->with([
    ['schema:inspect', 'table', 'users'],
    ['schema:history', 'table', 'users'],
    ['schema:trace', 'column', 'users.email'],
]);

it('rejects malformed path configuration as a command error', function () {
    config()->set('migration-atlas.paths', 'not-an-array');

    $this->artisan('schema:inspect', ['table' => 'users'])
        ->expectsOutput('Migration paths configuration must be an array of local directories.')
        ->assertExitCode(1);
});

it('does not consult invalid configured paths when overrides are provided', function () {
    config()->set('migration-atlas.paths', 'not-an-array');

    $this->artisan('schema:inspect', ['table' => 'users', '--path' => [dirname(__DIR__).'/Fixtures/migrations']])
        ->expectsOutput('The analyzer is not implemented yet (M1 discovery only).')
        ->assertExitCode(1);
});

it('surfaces discovery warnings while clearly reporting the unfinished analyzer', function () {
    $diagnostic = new Diagnostic(DiagnosticCode::NonStandardFilename, DiagnosticSeverity::Warning, 'Nonstandard migration name.', '/legacy_migration.php');
    $finder = Mockery::mock(MigrationFinder::class);
    $finder->shouldReceive('find')->once()->andReturn(new MigrationList([], new DiagnosticCollection([$diagnostic])));
    $this->app->instance(MigrationFinder::class, $finder);

    $this->artisan('schema:inspect', ['table' => 'users'])
        ->expectsOutput('NON_STANDARD_FILENAME: Nonstandard migration name.')
        ->expectsOutput('The analyzer is not implemented yet (M1 discovery only).')
        ->assertExitCode(1);
});
