<?php

declare(strict_types=1);

use MigrationAtlas\Diagnostics\Diagnostic;
use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Diagnostics\DiagnosticCollection;
use MigrationAtlas\Diagnostics\DiagnosticSeverity;
use MigrationAtlas\Normalization\OperationNormalizer;
use MigrationAtlas\Operations\AlterTable;
use MigrationAtlas\Operations\Attribution;
use MigrationAtlas\Operations\CreateTable;
use MigrationAtlas\Operations\DropColumn;
use MigrationAtlas\Operations\DropTable;
use MigrationAtlas\Operations\ExecutionCertainty;
use MigrationAtlas\Operations\RenameColumn;
use MigrationAtlas\Operations\RenameTable;
use MigrationAtlas\Operations\UnresolvedOperation;
use MigrationAtlas\Parsing\ParsedMigration;
use MigrationAtlas\Tests\Support\NormalizationFixture;

it('normalizes table lifecycle calls and nested column changes in order', function () {
    $result = NormalizationFixture::normalize('Schema::create("users", fn ($t) => $t->id());
Schema::table("users", function ($bp) {
    $bp->dropColumn(["old", "unused"]);
    $bp->renameColumn("name", "full_name");
});
Schema::rename("users", "members");
Schema::dropIfExists("members");
Schema::drop("old_users");');
    [$create, $alter, $rename, $dropIfExists, $drop] = $result->operations;

    expect($create)->toBeInstanceOf(CreateTable::class)
        ->and($alter)->toBeInstanceOf(AlterTable::class)
        ->and($alter->operations[0])->toBeInstanceOf(DropColumn::class)
        ->and($alter->operations[0]->columns)->toBe(['old', 'unused'])
        ->and($alter->operations[1])->toBeInstanceOf(RenameColumn::class)
        ->and($alter->operations[1]->from)->toBe('name')
        ->and($alter->operations[1]->to)->toBe('full_name')
        ->and($rename)->toBeInstanceOf(RenameTable::class)
        ->and($rename->from)->toBe('users')
        ->and($rename->to)->toBe('members')
        ->and($dropIfExists)->toBeInstanceOf(DropTable::class)
        ->and($dropIfExists->ifExists)->toBeTrue()
        ->and($drop->ifExists)->toBeFalse()
        ->and(array_map(fn ($op) => $op->source->position, $result->operations))->toBe([[7, 1], [7, 3], [7, 6], [7, 7], [7, 8]])
        ->and($result->diagnostics)->toHaveCount(0);
});

it('binds supported named arguments in their declared parameter slots', function () {
    $result = NormalizationFixture::normalize('Schema::create(callback: fn ($t) => $t->id(column: "pk"), table: "users");
Schema::table(callback: function ($t) {
    $t->dropColumn(columns: "legacy");
    $t->renameColumn(to: "new", from: "old");
}, table: "users");
Schema::rename(to: "members", from: "users");
Schema::drop(table: "members");');

    expect($result->operations[0]->columns[0]->name)->toBe('pk')
        ->and($result->operations[1]->operations[0]->columns)->toBe(['legacy'])
        ->and($result->operations[1]->operations[1]->from)->toBe('old')
        ->and($result->operations[2]->from)->toBe('users')
        ->and($result->operations[2]->to)->toBe('members')
        ->and($result->operations[3]->table)->toBe('members')
        ->and($result->diagnostics)->toHaveCount(0);
});

it('retains uncertainty for dynamic malformed or unavailable table effects', function (string $body, ?string $table, DiagnosticCode $cause) {
    $result = NormalizationFixture::normalize($body);
    $unknown = $result->operations[0];

    expect($unknown)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($unknown->table)->toBe($table)
        ->and($unknown->cause)->toBe($cause)
        ->and($unknown->wholeFileUnknown)->toBeFalse()
        ->and($result->diagnostics)->not->toHaveCount(0);
})->with([
    ['Schema::create($name, fn ($t) => $t->id());', null, DiagnosticCode::UnresolvedDynamicExpression],
    ['Schema::drop("prefix".$name);', null, DiagnosticCode::UnresolvedDynamicExpression],
    ['Schema::create("users", $callback);', 'users', DiagnosticCode::UnresolvedDynamicExpression],
    ['Schema::table("users");', 'users', DiagnosticCode::UnresolvedDynamicExpression],
    ['Schema::drop("users", "extra");', 'users', DiagnosticCode::UnresolvedDynamicExpression],
    ['Schema::drop(...$arguments);', null, DiagnosticCode::UnresolvedConditionalRegion],
    ['if ($enabled) { Schema::drop("users"); }', 'users', DiagnosticCode::UnresolvedConditionalRegion],
    ['foreach ($names as $name) { Schema::drop($name); }', null, DiagnosticCode::UnresolvedConditionalRegion],
]);

it('does not trust apparent targets of custom facade macros', function (string $method) {
    $result = NormalizationFixture::normalize('Schema::'.$method.'("users", fn ($t) => $t->id());');
    $unknown = $result->operations[0];

    expect($result->operations)->toHaveCount(1)
        ->and($unknown)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($unknown->table)->toBeNull()
        ->and($unknown->attribution)->toBe(Attribution::Unattributable)
        ->and($unknown->reason)->toBe(DiagnosticCode::UnattributableOperation)
        ->and($unknown->cause)->toBe(DiagnosticCode::UnresolvedUnknownMethod)
        ->and(array_column($result->diagnostics->items, 'code'))->toBe([
            DiagnosticCode::UnresolvedUnknownMethod, DiagnosticCode::UnattributableOperation,
        ]);
})->with(['createIfNotExists', 'customMacro']);

it('reports SQL with both diagnostics and no fabricated table', function (string $body, string $connection) {
    $result = NormalizationFixture::normalize($body);
    $unknown = $result->operations[0];

    expect($unknown)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($unknown->table)->toBeNull()
        ->and($unknown->connection)->toBe($connection)
        ->and($unknown->reason)->toBe(DiagnosticCode::UnattributableOperation)
        ->and($unknown->cause)->toBe(DiagnosticCode::RawSqlDetected)
        ->and(array_column($result->diagnostics->items, 'code'))->toBe([
            DiagnosticCode::RawSqlDetected, DiagnosticCode::UnattributableOperation,
        ]);
})->with([
    ['DB::statement("DROP TABLE users");', 'default'],
    ['DB::unprepared("ALTER TABLE users ADD x INT");', 'default'],
    ['Schema::getConnection()->statement("DROP TABLE users");', 'default'],
    ['DB::connection("archive")->statement("DROP TABLE users");', 'archive'],
]);

it('keeps column lifecycle uncertainty scoped to its table', function (string $expression) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->'.$expression.');');
    $unknown = $result->operations[0]->operations[0];

    expect($unknown)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($unknown->table)->toBe('users')
        ->and($unknown->attribution)->toBe(Attribution::Attributed)
        ->and($unknown->reason)->toBe(DiagnosticCode::UnresolvedDynamicExpression);
})->with(['dropColumn($column)', 'dropColumn(["valid", $dynamic])', 'dropColumn([])', 'dropColumn(["key" => "column"])',
    'dropColumn("a", "b")', 'renameColumn("old", $new)', 'renameColumn("old")']);

it('keeps conditional Blueprint calls unresolved within a known table', function () {
    $body = 'if ($flag) { $t->id(); }';
    $result = NormalizationFixture::normalize('Schema::create("users", function ($t) { '.$body.' });');

    expect($result->operations[0])->toBeInstanceOf(CreateTable::class)
        ->and($result->operations[0]->columns)->toBe([])
        ->and($result->operations[0]->operations[0])->toBeInstanceOf(UnresolvedOperation::class)
        ->and($result->operations[0]->operations[0]->table)->toBe('users');
});

it('preserves whole-call uncertainty when a callback contains opaque aliased effects', function (string $body) {
    $result = NormalizationFixture::normalize('Schema::create("users", function ($t) { '.$body.' });');

    expect($result->operations[0])->toBeInstanceOf(UnresolvedOperation::class)
        ->and($result->operations[0]->table)->toBe('users')
        ->and($result->operations[0]->cause)->toBe(DiagnosticCode::UnresolvedConditionalRegion);
})->with(['$alias = $t; $alias->id();', '$t = other(); $t->id();']);

it('stamps every supported nested operation conditional without repeating shouldRun diagnostics', function () {
    $result = NormalizationFixture::normalize('Schema::create("users", fn ($t) => $t->id());
Schema::table("users", function ($t) { $t->dropColumn("old"); $t->renameColumn("a", "b"); });
Schema::rename("users", "members"); Schema::dropIfExists("members");', 'public function shouldRun() { throw new \RuntimeException("never"); }');
    $nested = [...$result->operations, ...$result->operations[0]->operations, ...$result->operations[1]->operations];

    expect(array_unique(array_map(fn ($op) => $op->execution->value, $nested)))->toBe([ExecutionCertainty::Conditional->value])
        ->and(array_column($result->diagnostics->items, 'code'))->toBe([DiagnosticCode::ShouldRunDetected]);
});

it('emits exactly one ordered marker per failed file preserving the root warning', function (DiagnosticCode $code, ?int $line) {
    $valid = NormalizationFixture::parse('Schema::drop("users");', ordinal: 19);
    $root = new Diagnostic($code, DiagnosticSeverity::Warning, 'root warning', $valid->file->absolutePath, $line);
    $failed = new ParsedMigration($valid->file, $valid->calls, false, 'default', new DiagnosticCollection([$root]), false);

    $result = (new OperationNormalizer)->normalize($failed);
    $marker = $result->operations[0];

    expect($result->parsed)->toBeFalse()
        ->and($result->file)->toBe($valid->file)
        ->and($result->operations)->toHaveCount(1)
        ->and($marker)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($marker->wholeFileUnknown)->toBeTrue()
        ->and($marker->source->position)->toBe([19, 0])
        ->and($marker->source->line)->toBe($line)
        ->and($marker->table)->toBeNull()
        ->and($marker->connection)->toBeNull()
        ->and($marker->reason)->toBe($code)
        ->and($result->diagnostics->items)->toBe([$root]);
})->with([
    [DiagnosticCode::MigrationSyntaxError, 12], [DiagnosticCode::MigrationParseFailure, null],
    [DiagnosticCode::FileTooLarge, null], [DiagnosticCode::MigrationSourceUnreadable, null],
    [DiagnosticCode::MigrationClassNotFound, 2], [DiagnosticCode::UpMethodMissing, 3],
]);

it('keeps a verified empty body a known no-op and resets positions for every file', function () {
    $normalizer = new OperationNormalizer;
    $parsed = NormalizationFixture::parse('Schema::drop("users");', ordinal: 3);
    $first = $normalizer->normalize($parsed);
    $empty = $normalizer->normalize(NormalizationFixture::parse('', ordinal: 4));
    $again = $normalizer->normalize($parsed);

    expect($first->operations[0]->source->position)->toBe([3, 1])
        ->and($empty->operations)->toBe([])
        ->and($empty->parsed)->toBeTrue()
        ->and($empty->diagnostics)->toHaveCount(0)
        ->and(json_encode($again, JSON_THROW_ON_ERROR))->toBe(json_encode($first, JSON_THROW_ON_ERROR));
});

it('cannot lose a failed file even when a custom parser omitted its root warning', function () {
    $valid = NormalizationFixture::parse('Schema::drop("users");');
    $failed = new ParsedMigration($valid->file, $valid->calls, false, null, new DiagnosticCollection, false);

    $result = (new OperationNormalizer)->normalize($failed);

    expect($result->operations)->toHaveCount(1)
        ->and($result->operations[0]->wholeFileUnknown)->toBeTrue()
        ->and($result->operations[0]->reason)->toBe(DiagnosticCode::MigrationParseFailure)
        ->and($result->operations[0]->source->line)->toBeNull()
        ->and($result->diagnostics)->toHaveCount(1);
});

it('retains known names without fabricating default routing', function (string $body, string $members, array $targets, ?string $table) {
    $result = NormalizationFixture::normalize($body, $members);
    $unknown = $result->operations[0];

    expect($unknown)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($unknown->connection)->toBeNull()
        ->and($unknown->table)->toBe($table)
        ->and($unknown->relatedTables)->toBe($targets)
        ->and($unknown->reason)->toBe(DiagnosticCode::UnattributableOperation)
        ->and($unknown->attribution)->toBe($table === null ? Attribution::Unattributable : Attribution::NameScoped);
})->with([
    ['Schema::drop("users");', 'protected $connection = SOME_CONSTANT;', [], 'users'],
    ['Schema::connection($connection)->rename("users", "members");', 'protected $connection = "tenant";', ['members'], 'users'],
    ['Schema::rename("users", "members");', 'protected $connection = SOME_CONSTANT;', ['members'], 'users'],
    ['Schema::rename($from, "members");', 'protected $connection = SOME_CONSTANT;', ['members'], null],
    ['Schema::rename("users", $to);', 'protected $connection = SOME_CONSTANT;', ['users'], null],
]);

it('respects migration connection fallback and explicit wrapper precedence', function (string $members, string $expected) {
    $result = NormalizationFixture::normalize('Schema::create("users", fn ($t) => $t->id());
Schema::connection("archive")->drop("users");
Schema::connection(null)->rename("users", "members");', $members);

    expect(array_map(fn ($op) => $op->connection, $result->operations))->toBe([$expected, 'archive', $expected])
        ->and($result->operations[0]->operations[0]->connection)->toBe($expected);
})->with([['', 'default'], ['protected $connection = null;', 'default'], ['protected $connection = "";', 'default'],
    ['protected $connection = "0";', 'default'], ['protected $connection = "tenant";', 'tenant']]);

it('allows an explicit literal wrapper independently of an unknown migration connection', function () {
    $result = NormalizationFixture::normalize('Schema::connection("archive")->create("users", fn ($t) => $t->id());', 'protected $connection = SOME_CONSTANT;');

    expect($result->operations[0])->toBeInstanceOf(CreateTable::class)
        ->and($result->operations[0]->connection)->toBe('archive')
        ->and($result->diagnostics)->toHaveCount(0);
});
