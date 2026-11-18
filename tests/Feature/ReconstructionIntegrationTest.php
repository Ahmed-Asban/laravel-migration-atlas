<?php

declare(strict_types=1);

use MigrationAtlas\Discovery\DiscoveryOptions;
use MigrationAtlas\Discovery\MigrationFinder;
use MigrationAtlas\Normalization\OperationNormalizer;
use MigrationAtlas\Parsing\MigrationParser;
use MigrationAtlas\Reconstruction\FunctionalSchemaReconstructor;
use MigrationAtlas\Reconstruction\SchemaReconstructor;
use MigrationAtlas\Reconstruction\State\Existence;
use MigrationAtlas\Reconstruction\State\TableStatus;
use MigrationAtlas\Tests\Support\DiscoveryWorkspace;

beforeEach(function () {
    $this->workspace = new DiscoveryWorkspace;
});

afterEach(function () {
    $this->workspace->cleanup();
});

it('resolves the reconstructor through the container with every database connection blocked', function () {
    expect($this->app->make(SchemaReconstructor::class))->toBeInstanceOf(FunctionalSchemaReconstructor::class);
});

it('replays a discovered fixture corpus end to end without any database access', function () {
    $this->workspace->migration('migrations/2026_10_01_000000_create_users.php', '<?php
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::create("users", function ($t) {
            $t->id();
            $t->string("email")->nullable();
        });
    }
};');
    $this->workspace->migration('migrations/2026_10_02_000000_rename.php', '<?php
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::rename("users", "members");
    }
};');

    $files = $this->app->make(MigrationFinder::class)
        ->find(new DiscoveryOptions(['migrations'], $this->workspace->root))->files;
    $parser = $this->app->make(MigrationParser::class);
    $normalizer = $this->app->make(OperationNormalizer::class);
    $migrations = array_map(fn ($file) => $normalizer->normalize($parser->parse($file)), $files);
    $result = $this->app->make(SchemaReconstructor::class)->replay($migrations);

    $members = $result->registry->byName('default', 'members');

    expect($result->migrationsAnalyzed)->toBe(2)
        ->and(count($members))->toBe(1)
        ->and($members[0]->status)->toBe(TableStatus::Complete)
        ->and($members[0]->existence)->toBe(Existence::Present)
        ->and(array_keys($members[0]->columns))->toBe(['id', 'email'])
        ->and($members[0]->formerNames)->toBe(['users']);
});
