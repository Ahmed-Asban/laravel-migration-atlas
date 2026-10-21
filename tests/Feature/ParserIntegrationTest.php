<?php

declare(strict_types=1);

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Discovery\DiscoveryOptions;
use MigrationAtlas\Discovery\MigrationFile;
use MigrationAtlas\Discovery\MigrationFinder;
use MigrationAtlas\Parsing\MigrationParser;
use MigrationAtlas\Parsing\MigrationSourceReader;
use MigrationAtlas\Parsing\NativeMigrationSourceReader;
use MigrationAtlas\Parsing\PhpParserMigrationParser;
use MigrationAtlas\Tests\Support\DiscoveryWorkspace;

beforeEach(function () {
    $this->workspace = new DiscoveryWorkspace;
});

afterEach(function () {
    $this->workspace->cleanup();
});

it('resolves parsing interfaces while all database connections are blocked', function () {
    expect($this->app->make(MigrationParser::class))->toBeInstanceOf(PhpParserMigrationParser::class)
        ->and($this->app->make(MigrationSourceReader::class))->toBeInstanceOf(NativeMigrationSourceReader::class);
});

it('keeps aliased migration routing unknown while preserving an explicit wrapper', function () {
    $source = <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    protected $connection = 'tenant';
    public function up(): void {
        $alias = $this;
        $alias->connection = 'archive';
        \Illuminate\Support\Facades\Schema::drop('users');
        \Illuminate\Support\Facades\Schema::connection('isolated')->drop('other');
    }
};
PHP;
    $file = new MigrationFile($this->workspace->migration('alias.php', $source), 'alias', null, 9);

    $result = $this->app->make(MigrationParser::class)->parse($file);

    expect($result->parsed)->toBeTrue()
        ->and($result->connection)->toBeNull()
        ->and(array_column($result->calls, 'connection'))->toBe([null, 'isolated'])
        ->and($result->calls[1]->connectionExplicit)->toBeTrue();
});

it('honors the configured byte limit when constructing the parser', function () {
    config()->set('migration-atlas.max_file_size', 16);
    $path = $this->workspace->migration('migration.php', '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() {} };');
    $file = new MigrationFile($path, 'migration', null, 5);

    $result = $this->app->make(MigrationParser::class)->parse($file);

    expect($result->parsed)->toBeFalse()
        ->and($result->file)->toBe($file)
        ->and($result->diagnostics->items[0]->code)->toBe(DiagnosticCode::FileTooLarge);
});

it('keeps discovery order and continues after oversized syntax and disappeared sources', function () {
    $this->workspace->migration('migrations/2026_10_01_000000_oversized.php', str_repeat('x', 1025));
    $this->workspace->migration('migrations/2026_10_02_000000_broken.php', '<?php syntax error;');
    $disappeared = $this->workspace->migration('migrations/2026_10_03_000000_disappeared.php', '<?php');
    $this->workspace->migration('migrations/2026_10_04_000000_valid.php', '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() { \\Illuminate\\Support\\Facades\\Schema::drop("users"); } };');
    $files = $this->app->make(MigrationFinder::class)->find(new DiscoveryOptions(['migrations'], $this->workspace->root));
    unlink($disappeared);
    config()->set('migration-atlas.max_file_size', 1024);
    $parser = $this->app->make(MigrationParser::class);

    $results = array_map($parser->parse(...), $files->files);

    expect(array_map(fn ($result) => $result->file->ordinal, $results))->toBe([1, 2, 3, 4])
        ->and(array_column($results, 'parsed'))->toBe([false, false, false, true])
        ->and(array_map(fn ($result) => $result->diagnostics->items[0]->code, array_slice($results, 0, 3)))
        ->toBe([DiagnosticCode::FileTooLarge, DiagnosticCode::MigrationSyntaxError, DiagnosticCode::MigrationSourceUnreadable])
        ->and($results[0]->calls)->toBe([])
        ->and($results[1]->calls)->toBe([])
        ->and($results[2]->calls)->toBe([])
        ->and($results[3]->calls)->toHaveCount(1)
        ->and($results[3]->calls[0]->method)->toBe('drop');
});

it('analyzes hostile migration text with Laravel booted without executing it or accessing a database', function () {
    $source = file_get_contents(dirname(__DIR__).'/Fixtures/parsing/never-executed.php');
    $path = $this->workspace->migration('migrations/2026_10_21_000000_never_execute.php', $source);
    $files = $this->app->make(MigrationFinder::class)->find(new DiscoveryOptions(['migrations'], $this->workspace->root));

    $result = $this->app->make(MigrationParser::class)->parse($files->files[0]);

    expect($result->parsed)->toBeTrue()
        ->and(realpath($result->file->absolutePath))->toBe(realpath($path))
        ->and($result->conditional)->toBeTrue()
        ->and(array_column($result->diagnostics->items, 'code'))->toBe([DiagnosticCode::ShouldRunDetected])
        ->and(array_column($result->calls, 'method'))->toContain('create')->not->toContain('drop')
        ->and(file_exists($this->workspace->root.'/migrations/executed.flag'))->toBeFalse()
        ->and(file_exists($this->workspace->root.'/migrations/down-executed.flag'))->toBeFalse();
});

it('honors an application-supplied source reader without opening the source path', function () {
    $reader = new class implements MigrationSourceReader
    {
        public function read(MigrationFile $file, int $maxFileSize): string
        {
            return '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() {} };';
        }
    };
    $this->app->instance(MigrationSourceReader::class, $reader);
    $file = new MigrationFile($this->workspace->root.'/does-not-exist.php', 'virtual_migration', null, 8);

    $result = $this->app->make(MigrationParser::class)->parse($file);

    expect($result->parsed)->toBeTrue()
        ->and($result->file)->toBe($file)
        ->and($result->calls)->toBe([]);
});
