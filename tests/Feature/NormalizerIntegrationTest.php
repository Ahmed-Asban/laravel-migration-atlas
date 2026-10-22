<?php

declare(strict_types=1);

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Discovery\DiscoveryOptions;
use MigrationAtlas\Discovery\MigrationFinder;
use MigrationAtlas\Normalization\OperationNormalizer;
use MigrationAtlas\Normalization\SupportMatrix;
use MigrationAtlas\Operations\CreateTable;
use MigrationAtlas\Operations\UnresolvedOperation;
use MigrationAtlas\Parsing\MigrationParser;
use MigrationAtlas\Tests\Support\DiscoveryWorkspace;

beforeEach(function () {
    $this->workspace = new DiscoveryWorkspace;
});

afterEach(function () {
    $this->workspace->cleanup();
});

it('resolves normalization services with every database connection blocked', function () {
    expect($this->app->make(OperationNormalizer::class))->toBeInstanceOf(OperationNormalizer::class)
        ->and($this->app->make(SupportMatrix::class))->toBeInstanceOf(SupportMatrix::class);
});

it('discovers parses and normalizes sources in order while retaining failed-file positions', function () {
    $this->workspace->migration('migrations/2026_10_01_000000_broken.php', '<?php broken ; ; ?');
    $gone = $this->workspace->migration('migrations/2026_10_02_000000_gone.php', '<?php');
    $this->workspace->migration('migrations/2026_10_03_000000_large.php', str_repeat('x', 1025));
    $this->workspace->migration('migrations/2026_10_04_000000_empty.php', '<?php return new class extends \Illuminate\Database\Migrations\Migration { public function up() {} };');
    $this->workspace->migration('migrations/2026_10_05_000000_create.php', '<?php return new class extends \Illuminate\Database\Migrations\Migration { public function up() { \Illuminate\Support\Facades\Schema::create("users", fn ($t) => $t->id()); } };');
    $files = $this->app->make(MigrationFinder::class)->find(new DiscoveryOptions(['migrations'], $this->workspace->root));
    unlink($gone);
    config()->set('migration-atlas.max_file_size', 1024);
    $parser = $this->app->make(MigrationParser::class);
    $normalizer = $this->app->make(OperationNormalizer::class);
    $results = array_map(fn ($file) => $normalizer->normalize($parser->parse($file)), $files->files);

    expect(array_column($results, 'parsed'))->toBe([false, false, false, true, true])
        ->and(array_map(fn ($result) => count($result->operations), $results))->toBe([1, 1, 1, 0, 1]);

    foreach (array_slice($results, 0, 3) as $index => $result) {
        expect($result->operations[0])->toBeInstanceOf(UnresolvedOperation::class)
            ->and($result->operations[0]->wholeFileUnknown)->toBeTrue()
            ->and($result->operations[0]->source->position)->toBe([$index + 1, 0])
            ->and($result->diagnostics)->toHaveCount(1);
    }

    expect(array_map(fn ($result) => $result->operations[0]->reason, array_slice($results, 0, 3)))
        ->toBe([DiagnosticCode::MigrationSyntaxError, DiagnosticCode::MigrationSourceUnreadable, DiagnosticCode::FileTooLarge])
        ->and($results[4]->operations[0])->toBeInstanceOf(CreateTable::class)
        ->and($results[4]->operations[0]->source->position)->toBe([5, 1]);
});

it('normalizes hostile source as data without executing any migration method', function () {
    $source = file_get_contents(dirname(__DIR__).'/Fixtures/normalization/uncertainty.php');
    $this->workspace->migration('migrations/2026_10_22_000000_hostile.php', $source);
    $file = $this->app->make(MigrationFinder::class)->find(new DiscoveryOptions(['migrations'], $this->workspace->root))->files[0];

    $result = $this->app->make(OperationNormalizer::class)->normalize($this->app->make(MigrationParser::class)->parse($file));

    expect($result->parsed)->toBeTrue()
        ->and($result->operations[0])->toBeInstanceOf(CreateTable::class)
        ->and($result->operations[0]->columns)->toHaveCount(1)
        ->and(array_filter($result->diagnostics->items, fn ($diagnostic) => $diagnostic->code === DiagnosticCode::ShouldRunDetected))->toHaveCount(1)
        ->and(isset($GLOBALS['migration_atlas_normalization_executed']))->toBeFalse();
});
