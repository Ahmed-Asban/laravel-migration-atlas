<?php

declare(strict_types=1);

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Diagnostics\DiagnosticSeverity;
use MigrationAtlas\Discovery\MigrationFile;
use MigrationAtlas\Parsing\MigrationSourceReader;
use MigrationAtlas\Parsing\PhpParserMigrationParser;
use MigrationAtlas\Parsing\SourceReadFailure;
use MigrationAtlas\Tests\Support\DiscoveryWorkspace;
use PhpParser\Error as AstSyntaxError;
use PhpParser\ErrorHandler;
use PhpParser\Parser;

function migrationFileForParserTest(DiscoveryWorkspace $workspace, string $source, int $ordinal = 7): MigrationFile
{
    $name = '2026_10_21_000000_create_users';

    return new MigrationFile(
        $workspace->migration($name.'.php', $source),
        $name,
        '2026-10-21 00:00:00',
        $ordinal,
    );
}

function failingAstParserForMigrationTest(?Throwable $failure): Parser
{
    return new class($failure) implements Parser
    {
        public function __construct(private readonly ?Throwable $failure) {}

        public function parse(string $code, ?ErrorHandler $errorHandler = null): ?array
        {
            if ($this->failure !== null) {
                throw $this->failure;
            }

            return null;
        }

        public function getTokens(): array
        {
            return [];
        }
    };
}

beforeEach(function () {
    $this->workspace = new DiscoveryWorkspace;
});

afterEach(function () {
    $this->workspace->cleanup();
});

it('parses named and anonymous migrations with resolved imports and source provenance', function (string $declaration) {
    $source = "<?php\nuse Illuminate\\Database\\Migrations\\Migration as Base;\nuse Illuminate\\Support\\Facades\\Schema as Structure;\n".$declaration;
    $file = migrationFileForParserTest($this->workspace, $source);

    $result = (new PhpParserMigrationParser)->parse($file);

    expect($result->parsed)->toBeTrue()
        ->and($result->file)->toBe($file)
        ->and($result->file->ordinal)->toBe(7)
        ->and($result->connection)->toBe('archive')
        ->and($result->conditional)->toBeFalse()
        ->and($result->diagnostics)->toHaveCount(0)
        ->and($result->calls)->toHaveCount(1)
        ->and($result->calls[0]->method)->toBe('create')
        ->and($result->calls[0]->connection)->toBe('archive')
        ->and($result->calls[0]->arguments[0]->value)->toBe('users')
        ->and($result->calls[0]->line)->toBe(7)
        ->and($result->calls[0]->source)->toContain("Structure::create('users'")
        ->and($result->calls[0]->blueprintCalls[0]->rootCall->method)->toBe('id');
})->with([
    'named' => ["class CreateUsers extends Base {\n    protected \$connection = 'archive';\n    public function up() {\n        Structure::create('users', fn (\$bp) => \$bp->id());\n    }\n}"],
    'anonymous' => ["return new class extends Base {\n    protected \$connection = 'archive';\n    public function up() {\n        Structure::create('users', fn (\$bp) => \$bp->id());\n    }\n};"],
]);

it('treats a verified empty up body as a parsed no-op', function () {
    $file = migrationFileForParserTest($this->workspace, '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() {} };');

    $result = (new PhpParserMigrationParser)->parse($file);

    expect($result->parsed)->toBeTrue()
        ->and($result->file)->toBe($file)
        ->and($result->calls)->toBe([])
        ->and($result->diagnostics)->toHaveCount(0)
        ->and($result->connection)->toBe('default');
});

it('reads migration source without executing top-level statements shouldRun up or down', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/Fixtures/parsing/never-executed.php');
    $file = migrationFileForParserTest($this->workspace, $source);

    $result = (new PhpParserMigrationParser)->parse($file);

    expect($result->parsed)->toBeTrue()
        ->and($result->conditional)->toBeTrue()
        ->and(array_column($result->diagnostics->items, 'code'))->toBe([DiagnosticCode::ShouldRunDetected])
        ->and(array_column($result->calls, 'method'))->toContain('create')->not->toContain('drop')
        ->and(file_exists($this->workspace->root.'/executed.flag'))->toBeFalse()
        ->and(file_exists($this->workspace->root.'/down-executed.flag'))->toBeFalse();
});

it('marks literal true and false shouldRun bodies conditional without constant folding', function (string $value) {
    $file = migrationFileForParserTest($this->workspace, '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration {
        public function shouldRun() { return '.$value.'; }
        public function up() {}
    };');

    $result = (new PhpParserMigrationParser)->parse($file);

    expect($result->parsed)->toBeTrue()
        ->and($result->conditional)->toBeTrue()
        ->and($result->diagnostics)->toHaveCount(1)
        ->and($result->diagnostics->items[0]->code)->toBe(DiagnosticCode::ShouldRunDetected)
        ->and($result->diagnostics->items[0]->severity)->toBe(DiagnosticSeverity::Warning)
        ->and($result->diagnostics->items[0]->file)->toBe($file->absolutePath);
})->with(['true', 'false']);

it('discards all calls after a syntax failure while preserving file metadata and error line', function () {
    $source = <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up() {
        \Illuminate\Support\Facades\Schema::create('users', fn ($table) => $table->id());
        $broken = ;
    }
};
PHP;
    $file = migrationFileForParserTest($this->workspace, $source, 23);

    $result = (new PhpParserMigrationParser)->parse($file);

    expect($result->parsed)->toBeFalse()
        ->and($result->file)->toBe($file)
        ->and($result->file->ordinal)->toBe(23)
        ->and($result->calls)->toBe([])
        ->and($result->connection)->toBeNull()
        ->and($result->diagnostics)->toHaveCount(1)
        ->and($result->diagnostics->items[0]->code)->toBe(DiagnosticCode::MigrationSyntaxError)
        ->and($result->diagnostics->items[0]->severity)->toBe(DiagnosticSeverity::Warning)
        ->and($result->diagnostics->items[0]->file)->toBe($file->absolutePath)
        ->and($result->diagnostics->items[0]->line)->toBe(5);
});

it('converts recoverable AST failures to one root diagnostic without partial calls', function (?Throwable $failure, DiagnosticCode $code, ?int $line) {
    $file = migrationFileForParserTest($this->workspace, '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() {} };');
    $parser = new PhpParserMigrationParser(parser: failingAstParserForMigrationTest($failure));

    $result = $parser->parse($file);

    expect($result->parsed)->toBeFalse()
        ->and($result->file)->toBe($file)
        ->and($result->calls)->toBe([])
        ->and($result->diagnostics)->toHaveCount(1)
        ->and($result->diagnostics->items[0]->code)->toBe($code)
        ->and($result->diagnostics->items[0]->line)->toBe($line);
})->with([
    'runtime exception' => [new RuntimeException('Injected recoverable parser failure.'), DiagnosticCode::MigrationParseFailure, null],
    'unexpected throwable' => [new TypeError('Injected parser type failure.'), DiagnosticCode::MigrationParseFailure, null],
    'null AST' => [null, DiagnosticCode::MigrationParseFailure, null],
    'syntax without location' => [new AstSyntaxError('Injected syntax error.'), DiagnosticCode::MigrationSyntaxError, null],
    'syntax with location' => [new AstSyntaxError('Injected syntax error.', ['startLine' => 11]), DiagnosticCode::MigrationSyntaxError, 11],
]);

it('preserves source-read failure codes and nullable locations', function (Throwable $failure, DiagnosticCode $code) {
    $file = migrationFileForParserTest($this->workspace, '<?php');
    $reader = new class($failure) implements MigrationSourceReader
    {
        public function __construct(private readonly Throwable $failure) {}

        public function read(MigrationFile $file, int $maxFileSize): string
        {
            throw $this->failure;
        }
    };

    $result = (new PhpParserMigrationParser(sources: $reader))->parse($file);

    expect($result->parsed)->toBeFalse()
        ->and($result->file)->toBe($file)
        ->and($result->calls)->toBe([])
        ->and($result->diagnostics)->toHaveCount(1)
        ->and($result->diagnostics->items[0]->code)->toBe($code)
        ->and($result->diagnostics->items[0]->file)->toBe($file->absolutePath)
        ->and($result->diagnostics->items[0]->line)->toBeNull();
})->with([
    'read failure' => [new SourceReadFailure(DiagnosticCode::MigrationSourceUnreadable, 'Injected unreadable file.'), DiagnosticCode::MigrationSourceUnreadable],
    'size failure' => [new SourceReadFailure(DiagnosticCode::FileTooLarge, 'Injected oversized file.'), DiagnosticCode::FileTooLarge],
    'unexpected source failure' => [new RuntimeException('Injected source exception.'), DiagnosticCode::MigrationSourceUnreadable],
]);

it('does not interpret missing or uninspectable migration bodies as successful no-ops', function (string $source, DiagnosticCode $code) {
    $file = migrationFileForParserTest($this->workspace, '<?php '.$source);

    $result = (new PhpParserMigrationParser)->parse($file);

    expect($result->parsed)->toBeFalse()
        ->and($result->file)->toBe($file)
        ->and($result->calls)->toBe([])
        ->and($result->diagnostics)->toHaveCount(1)
        ->and($result->diagnostics->items[0]->code)->toBe($code);
})->with([
    'no migration' => ['class Helper {}', DiagnosticCode::MigrationClassNotFound],
    'missing up' => ['return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function down() {} };', DiagnosticCode::UpMethodMissing],
    'abstract up' => ['abstract class Pending extends \\Illuminate\\Database\\Migrations\\Migration { abstract public function up(); }', DiagnosticCode::UpMethodMissing],
    'trait-provided up' => ['return new class extends \\Illuminate\\Database\\Migrations\\Migration { use UninspectableUp; };', DiagnosticCode::UpMethodMissing],
]);

it('accepts a source exactly at the configured byte limit and rejects one additional byte', function () {
    $source = '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() {} };';
    $file = migrationFileForParserTest($this->workspace, $source);

    $accepted = (new PhpParserMigrationParser(maxFileSize: strlen($source)))->parse($file);
    $rejected = (new PhpParserMigrationParser(maxFileSize: strlen($source) - 1))->parse($file);

    expect($accepted->parsed)->toBeTrue()
        ->and($rejected->parsed)->toBeFalse()
        ->and($rejected->file)->toBe($file)
        ->and($rejected->calls)->toBe([])
        ->and($rejected->diagnostics)->toHaveCount(1)
        ->and($rejected->diagnostics->items[0]->code)->toBe(DiagnosticCode::FileTooLarge)
        ->and($rejected->diagnostics->items[0]->line)->toBeNull();
});

it('enforces the source size cap even when an injected reader returns oversized text', function () {
    $reader = new class implements MigrationSourceReader
    {
        public function read(MigrationFile $file, int $maxFileSize): string
        {
            return str_repeat('x', $maxFileSize + 1);
        }
    };
    $file = migrationFileForParserTest($this->workspace, '<?php');

    $result = (new PhpParserMigrationParser(maxFileSize: 16, sources: $reader))->parse($file);

    expect($result->parsed)->toBeFalse()
        ->and($result->calls)->toBe([])
        ->and($result->diagnostics)->toHaveCount(1)
        ->and($result->diagnostics->items[0]->code)->toBe(DiagnosticCode::FileTooLarge);
});

it('retains opaque side effects and SQL as raw uncertainty without issuing normalization diagnostics', function () {
    $file = migrationFileForParserTest($this->workspace, '<?php
        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Support\\Facades\\DB;
        use Illuminate\\Support\\Facades\\Schema;
        return new class extends Migration {
            public function up() {
                rebuild_schema();
                DB::statement("ALTER TABLE users ADD active BOOLEAN");
                Schema::drop("legacy");
            }
        };');

    $result = (new PhpParserMigrationParser)->parse($file);

    expect($result->parsed)->toBeTrue()
        ->and($result->diagnostics)->toHaveCount(0)
        ->and($result->calls)->toHaveCount(3)
        ->and($result->calls[0]->method)->toBeNull()
        ->and($result->calls[0]->unresolvedRegion)->toBeTrue()
        ->and($result->calls[0]->source)->toBe('rebuild_schema()')
        ->and($result->calls[1]->rawSql)->toBeTrue()
        ->and($result->calls[2]->method)->toBe('drop');
});

it('rejects invalid size limits before reading migration sources', function (int $limit) {
    expect(fn () => new PhpParserMigrationParser(maxFileSize: $limit))->toThrow(InvalidArgumentException::class);
})->with([0, -1, PHP_INT_MAX]);

it('can parse subsequent files after a failure without leaking previous file state', function () {
    $bad = migrationFileForParserTest($this->workspace, '<?php syntax error;', 2);
    $good = new MigrationFile(
        $this->workspace->migration('2026_10_22_000000_create_posts.php', '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() { \\Illuminate\\Support\\Facades\\Schema::drop("posts"); } };'),
        '2026_10_22_000000_create_posts',
        '2026-10-22 00:00:00',
        3,
    );
    $parser = new PhpParserMigrationParser;

    $failed = $parser->parse($bad);
    $parsed = $parser->parse($good);

    expect($failed->parsed)->toBeFalse()
        ->and($failed->file->ordinal)->toBe(2)
        ->and($parsed->parsed)->toBeTrue()
        ->and($parsed->file)->toBe($good)
        ->and($parsed->file->ordinal)->toBe(3)
        ->and($parsed->calls)->toHaveCount(1)
        ->and($parsed->calls[0]->arguments[0]->value)->toBe('posts')
        ->and($parsed->diagnostics)->toHaveCount(0);
});
