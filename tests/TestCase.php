<?php

declare(strict_types=1);

namespace MigrationAtlas\Tests;

use Illuminate\Database\DatabaseManager;
use MigrationAtlas\MigrationAtlasServiceProvider;
use Mockery;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MigrationAtlasServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'migration-atlas-disabled');
        $app['config']->set('database.connections', []);

        // Laravel's own provider registers an Eloquent resolver at boot. A mock
        // permits that wiring while rejecting connection attempts and DB calls.
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection');

        $app->instance('db', $database);
    }
}
