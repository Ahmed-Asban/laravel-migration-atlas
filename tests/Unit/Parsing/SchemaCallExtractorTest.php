<?php

declare(strict_types=1);

use MigrationAtlas\Discovery\MigrationFile;
use MigrationAtlas\Parsing\MigrationSourceReader;
use MigrationAtlas\Parsing\ParsedMigration;
use MigrationAtlas\Parsing\PhpParserMigrationParser;

function parseSourceForSchemaExtractorTest(string $source): ParsedMigration
{
    $reader = new class($source) implements MigrationSourceReader
    {
        public function __construct(private readonly string $source) {}

        public function read(MigrationFile $file, int $maxFileSize): string
        {
            return $this->source;
        }
    };

    $result = (new PhpParserMigrationParser(sources: $reader))->parse(
        new MigrationFile(__FILE__, '2026_10_22_000000_schema_extractor', '2026-10-22 00:00:00', 1),
    );

    expect($result->parsed)->toBeTrue()
        ->and($result->diagnostics)->toHaveCount(0);

    return $result;
}

function parseBodyForSchemaExtractorTest(string $body, string $declaration = "protected \$connection = 'tenant';"): ParsedMigration
{
    return parseSourceForSchemaExtractorTest('<?php
        use Illuminate\\Support\\Facades\\Schema;
        use Illuminate\\Support\\Facades\\DB;
        return new class extends \\Illuminate\\Database\\Migrations\\Migration {
            '.$declaration.'
            public function up() { '.$body.' }
        };');
}

it('extracts direct table calls and arrow callbacks without duplicating blueprint calls', function () {
    $result = parseBodyForSchemaExtractorTest("Schema::create('users', fn (\$bp) => \$bp->id()); Schema::table('users', function (\$blueprint) { \$blueprint->string('name')->nullable(); });");

    expect(array_column($result->calls, 'method'))->toBe(['create', 'table'])
        ->and(array_column($result->calls, 'connection'))->toBe(['tenant', 'tenant'])
        ->and(array_column($result->calls, 'unresolvedRegion'))->toBe([false, false])
        ->and($result->calls[0]->blueprintCalls[0]->rootCall->method)->toBe('id')
        ->and($result->calls[1]->blueprintCalls[0]->rootCall->method)->toBe('string')
        ->and($result->calls[1]->blueprintCalls[0]->modifiers[0]->method)->toBe('nullable');
});

it('does not invoke closure arguments on methods without known callback semantics', function (string $body) {
    $result = parseBodyForSchemaExtractorTest($body);

    expect($result->calls)->toHaveCount(1)
        ->and(array_column($result->calls, 'method'))->not->toContain('create')
        ->and($result->calls[0]->blueprintCalls)->toBe([]);
})->with([
    'drop extra argument' => ["Schema::drop('old', fn (\$bp) => Schema::create('phantom', fn (\$b) => \$b->id()));"],
    'custom macro' => ["Schema::customMacro('old', fn (\$bp) => Schema::create('phantom', fn (\$b) => \$b->id()));"],
    'SQL extra argument' => ["DB::statement('SELECT 1', fn (\$bp) => Schema::create('phantom', fn (\$b) => \$b->id()));"],
]);

it('marks grouped parent and nested callback effects unresolved while keeping their own connections', function () {
    $result = parseBodyForSchemaExtractorTest(<<<'PHP'
Schema::connection('archive')->create('users', function ($bp) {
    $bp->id();
    Schema::drop('old');
    DB::statement('ALTER TABLE other ADD flag BOOLEAN');
    $bp->string('name');
});
PHP);

    expect(array_column($result->calls, 'method'))->toBe(['create', 'drop', 'statement'])
        ->and(array_column($result->calls, 'connection'))->toBe(['archive', 'tenant', 'tenant'])
        ->and(array_column($result->calls, 'unresolvedRegion'))->toBe([true, true, true])
        ->and(array_column($result->calls, 'rawSql'))->toBe([false, false, true])
        ->and(array_map(fn ($call) => $call->rootCall->method, $result->calls[0]->blueprintCalls))->toBe(['id', 'string']);
});

it('keeps opaque callback effects instead of assuming that blueprint operations are complete', function () {
    $result = parseBodyForSchemaExtractorTest("Schema::create('users', function (\$bp) { \$bp->id(); rebuild_schema(); });");

    expect(array_column($result->calls, 'method'))->toBe(['create', null])
        ->and(array_column($result->calls, 'unresolvedRegion'))->toBe([true, true])
        ->and($result->calls[1]->source)->toBe('rebuild_schema()');
});

it('marks parent and subsequent operations unresolved when callbacks may abort outer execution', function (string $statement) {
    $result = parseBodyForSchemaExtractorTest("Schema::create('users', function (\$bp) { $statement }); Schema::drop('other');");

    expect(array_column($result->calls, 'method'))->toBe(['create', 'drop'])
        ->and(array_column($result->calls, 'unresolvedRegion'))->toBe([true, true]);
})->with([
    'exit' => ['exit;'],
    'throw variable' => ['throw $error;'],
    'conditional exit' => ['if ($stop) { exit; }'],
    'conditional throw' => ['if ($stop) { throw $error; }'],
]);

it('keeps callback-local returns from tainting parent or later outer execution', function () {
    $result = parseBodyForSchemaExtractorTest("Schema::connection('archive')->create('users', function (\$bp) { \$bp->id(); return; }); Schema::drop('other');");

    expect(array_column($result->calls, 'method'))->toBe(['create', 'drop'])
        ->and(array_column($result->calls, 'connection'))->toBe(['archive', 'tenant'])
        ->and(array_column($result->calls, 'unresolvedRegion'))->toBe([false, false])
        ->and($result->calls[0]->blueprintCalls[0]->rootCall->method)->toBe('id');
});

it('retains executed SQL arguments before the containing schema operation', function () {
    $result = parseBodyForSchemaExtractorTest("Schema::drop(DB::statement('DROP TABLE legacy'));");

    expect(array_column($result->calls, 'method'))->toBe(['statement', 'drop'])
        ->and(array_column($result->calls, 'rawSql'))->toBe([true, false])
        ->and($result->calls[0]->source)->toBe("DB::statement('DROP TABLE legacy')");
});

it('retains opaque helper effects in noncallback schema arguments before the parent call', function () {
    $result = parseBodyForSchemaExtractorTest("Schema::create('users', fn (\$bp) => \$bp->id(), rebuild_schema());");

    expect(array_column($result->calls, 'method'))->toBe([null, 'create'])
        ->and($result->calls[0]->source)->toBe('rebuild_schema()')
        ->and($result->calls[0]->unresolvedRegion)->toBeTrue()
        ->and($result->calls[1]->arguments[0]->value)->toBe('users');
});

it('retains known argument effects before opaque calls without visiting deferred callbacks', function () {
    $result = parseBodyForSchemaExtractorTest("register_change(Schema::drop('users'), fn () => Schema::drop('deferred'));");

    expect(array_column($result->calls, 'method'))->toBe(['drop', null])
        ->and($result->calls[0]->arguments[0]->value)->toBe('users')
        ->and($result->calls[1]->unresolvedRegion)->toBeTrue();
});

it('rejects arbitrary facade chains instead of treating their final method as a supported call', function (string $body) {
    $result = parseBodyForSchemaExtractorTest($body);

    expect($result->calls)->toHaveCount(1)
        ->and($result->calls[0]->method)->toBeNull()
        ->and($result->calls[0]->unresolvedRegion)->toBeTrue()
        ->and($result->calls[0]->connection)->toBeNull();
})->with([
    'drop then create' => ["Schema::drop('old')->create('phantom', fn (\$bp) => \$bp->id());"],
    'create then drop' => ["Schema::create('phantom', fn (\$bp) => \$bp->id())->drop('old');"],
    'arbitrary builder method' => ["Schema::connection('archive')->customBuilder()->drop('old');"],
]);

it('marks schema calls after possible early exits unresolved', function (string $prefix) {
    $result = parseBodyForSchemaExtractorTest($prefix." Schema::drop('users');");
    $drops = array_values(array_filter($result->calls, fn ($call) => $call->method === 'drop'));

    expect($drops)->toHaveCount(1)
        ->and($drops[0]->unresolvedRegion)->toBeTrue();
})->with([
    'unconditional return' => ['return;'],
    'conditional return' => ['if ($skip) { return; }'],
    'unconditional throw' => ['throw new RuntimeException;'],
    'conditional throw' => ['if ($skip) { throw new RuntimeException; }'],
    'exit' => ['exit;'],
    'goto' => ['goto finished; finished:'],
]);

it('marks schema effects within control flow unresolved without folding literal conditions', function (string $body) {
    $result = parseBodyForSchemaExtractorTest($body);
    $drops = array_values(array_filter($result->calls, fn ($call) => $call->method === 'drop'));

    expect($drops)->toHaveCount(1)
        ->and($drops[0]->unresolvedRegion)->toBeTrue();
})->with([
    'literal if' => ["if (true) { Schema::drop('users'); }"],
    'foreach' => ["foreach (\$items as \$item) { Schema::drop('users'); }"],
    'while' => ["while (\$repeat) { Schema::drop('users'); }"],
    'try' => ["try { Schema::drop('users'); } catch (Throwable \$error) {}"],
    'ternary' => ["\$skip ? Schema::drop('users') : null;"],
    'short circuit' => ["\$skip && Schema::drop('users');"],
]);

it('inherits migration routing for absent and falsy literal connection wrappers', function (string $argument) {
    $result = parseBodyForSchemaExtractorTest("Schema::connection($argument)->drop('users');");

    expect($result->calls)->toHaveCount(1)
        ->and($result->calls[0]->method)->toBe('drop')
        ->and($result->calls[0]->connection)->toBe('tenant')
        ->and($result->calls[0]->connectionExplicit)->toBeTrue()
        ->and($result->calls[0]->unresolvedRegion)->toBeFalse();
})->with([
    'missing argument' => [''],
    'null' => ['null'],
    'empty string' => ["''"],
    'zero string' => ["'0'"],
]);

it('keeps literal wrapper routing independent of unresolved migration routing', function () {
    $result = parseBodyForSchemaExtractorTest("Schema::drop('first'); Schema::connection('archive')->drop('second');", 'protected $connection = self::TARGET;');

    expect($result->connection)->toBeNull()
        ->and(array_column($result->calls, 'connection'))->toBe([null, 'archive'])
        ->and(array_column($result->calls, 'connectionExplicit'))->toBe([false, true]);
});

it('preserves named arguments while binding a reordered named callback', function () {
    $result = parseBodyForSchemaExtractorTest("Schema::connection(name: 'archive')->create(callback: fn (\$bp) => \$bp->id(), table: 'users');");

    expect($result->calls)->toHaveCount(1)
        ->and($result->calls[0]->connection)->toBe('archive')
        ->and(array_column($result->calls[0]->arguments, 'name'))->toBe(['callback', 'table'])
        ->and($result->calls[0]->arguments[1]->value)->toBe('users')
        ->and($result->calls[0]->callbackResolved)->toBeTrue()
        ->and($result->calls[0]->blueprintCalls[0]->rootCall->method)->toBe('id');
});

it('never defaults dynamic or malformed connection wrappers to known migration routing', function (string $argument) {
    $result = parseBodyForSchemaExtractorTest("Schema::connection($argument)->drop('users');");
    $drops = array_values(array_filter($result->calls, fn ($call) => $call->method === 'drop'));

    expect($drops)->toHaveCount(1)
        ->and($drops[0]->connection)->toBeNull()
        ->and($drops[0]->connectionExplicit)->toBeTrue();
})->with([
    'variable' => ['$connection'],
    'function' => ["config('database.default')"],
    'spread' => ['...$connections'],
    'unexpected arguments' => ["'archive', 'extra'"],
    'incorrect named argument' => ["other: 'archive'"],
]);

it('resolves facade aliases and grouped imports', function () {
    $result = parseSourceForSchemaExtractorTest(<<<'PHP'
<?php
namespace App\Migrations;
use Illuminate\Support\Facades\{Schema as Structure, DB as Database};
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up() {
        Structure::drop('users');
        Database::statement('DROP TABLE other');
    }
};
PHP);

    expect(array_column($result->calls, 'method'))->toBe(['drop', 'statement'])
        ->and(array_column($result->calls, 'rawSql'))->toBe([false, true]);
});

it('recognizes fully qualified global facade aliases', function () {
    $result = parseBodyForSchemaExtractorTest("\\Schema::drop('users'); \\DB::unprepared('DROP TABLE other');");

    expect(array_column($result->calls, 'method'))->toBe(['drop', 'unprepared'])
        ->and(array_column($result->calls, 'rawSql'))->toBe([false, true]);
});

it('does not confuse namespaced classes with the Laravel facades', function (string $prefix, string $body) {
    $result = parseSourceForSchemaExtractorTest('<?php '.$prefix.' return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() { '.$body.' } };');

    expect($result->calls)->toHaveCount(1)
        ->and($result->calls[0]->method)->toBeNull()
        ->and($result->calls[0]->rawSql)->toBeFalse()
        ->and($result->calls[0]->unresolvedRegion)->toBeTrue();
})->with([
    'namespaced Schema' => ['namespace App;', "Schema::drop('users');"],
    'unrelated imported Schema' => ['use App\\Schema;', "Schema::drop('users');"],
    'namespaced DB' => ['namespace App;', "DB::statement('DROP TABLE users');"],
]);

it('detects raw SQL through direct and connection wrapped calls', function (string $body, ?string $connection) {
    $result = parseBodyForSchemaExtractorTest($body);

    expect($result->calls)->toHaveCount(1)
        ->and($result->calls[0]->rawSql)->toBeTrue()
        ->and($result->calls[0]->connection)->toBe($connection);
})->with([
    'direct statement' => ["DB::statement('DROP TABLE users');", 'tenant'],
    'direct unprepared' => ["DB::unprepared('DROP TABLE users');", 'tenant'],
    'DB connection wrapper' => ["DB::connection('archive')->statement('DROP TABLE users');", 'archive'],
    'Schema getConnection' => ["Schema::getConnection()->statement('DROP TABLE users');", 'tenant'],
    'Schema connection getConnection' => ["Schema::connection('archive')->getConnection()->unprepared('DROP TABLE users');", 'archive'],
]);

it('finds SQL hidden in blueprint default expressions without treating DB raw as execution', function () {
    $result = parseBodyForSchemaExtractorTest(<<<'PHP'
Schema::create('users', function ($bp) {
    $bp->string('name')->default(DB::raw(DB::statement('DROP TABLE other')));
});
PHP);

    expect(array_column($result->calls, 'method'))->toBe(['create', 'statement'])
        ->and(array_column($result->calls, 'rawSql'))->toBe([false, true])
        ->and(array_column($result->calls, 'unresolvedRegion'))->toBe([true, true]);
});

it('treats expression-only DB raw and getConnection acquisition as non-SQL', function () {
    $result = parseBodyForSchemaExtractorTest("DB::raw('CURRENT_TIMESTAMP'); Schema::getConnection();");

    expect(array_column($result->calls, 'rawSql'))->not->toContain(true);
});

it('does not treat first class callable definitions as definite schema execution', function (string $body) {
    $result = parseBodyForSchemaExtractorTest($body);

    foreach ($result->calls as $call) {
        expect($call->unresolvedRegion)->toBeTrue();
    }
})->with([
    'schema callable' => ['$callable = Schema::drop(...);'],
    'SQL callable' => ['$callable = DB::statement(...);'],
    'connection callable' => ["\$callable = Schema::connection('archive')->drop(...);"],
]);

it('ignores deferred closure function and class bodies in up', function () {
    $result = parseBodyForSchemaExtractorTest(<<<'PHP'
$closure = function () { Schema::drop('closure_only'); };
$arrow = fn () => Schema::drop('arrow_only');
function local_helper() { Schema::drop('function_only'); }
$helper = new class { public function mutate() { Schema::drop('class_only'); } };
PHP);

    expect(array_column($result->calls, 'method'))->not->toContain('drop');
});

it('reports arbitrary constructor calls as opaque side effects', function () {
    $result = parseBodyForSchemaExtractorTest('$helper = new SchemaMutatingHelper();');

    expect($result->calls)->toHaveCount(1)
        ->and($result->calls[0]->method)->toBeNull()
        ->and($result->calls[0]->unresolvedRegion)->toBeTrue()
        ->and($result->calls[0]->source)->toBe('new SchemaMutatingHelper()');
});

it('preserves literal PHP string bytes in the raw model without lossy transcoding', function () {
    $result = parseBodyForSchemaExtractorTest(<<<'PHP'
Schema::create('users', fn ($bp) => $bp->string('name')->default("\xFF\x00"));
PHP);

    expect($result->calls[0]->blueprintCalls[0]->modifiers[0]->arguments[0]->value)->toBe("\xFF\x00")
        ->and($result->calls[0]->source)->toContain('"\\xFF\\x00"');
});

it('retains source positions for wrapper methods callback roots and fluent modifiers', function () {
    $result = parseSourceForSchemaExtractorTest(<<<'PHP'
<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up() {
        Schema::connection('archive')
            ->create('users', function ($bp) {
                $bp->string('name')
                    ->nullable()
                    ->default('guest');
            });
    }
};
PHP);
    $call = $result->calls[0];
    $column = $call->blueprintCalls[0];

    expect($call->line)->toBe(6)
        ->and($call->source)->toStartWith("Schema::connection('archive')")
        ->and($column->rootCall->line)->toBe(7)
        ->and(array_column($column->modifiers, 'line'))->toBe([8, 9]);
});
