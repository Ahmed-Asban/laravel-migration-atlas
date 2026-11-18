<?php

declare(strict_types=1);

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Normalization\OperationNormalizer;
use MigrationAtlas\Normalization\SupportMatrix;
use MigrationAtlas\Operations\AddColumn;
use MigrationAtlas\Operations\Attribution;
use MigrationAtlas\Operations\ChangeColumn;
use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Operations\UnresolvedOperation;
use MigrationAtlas\Tests\Support\NormalizationFixture;

it('records each tier two column with its raw type and literal arguments at reduced fidelity', function (string $expression, string $name, string $type, array $attributes) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->'.$expression.');');
    $column = $result->operations[0]->operations[0]->column;

    expect($column->name)->toBe($name)
        ->and($column->type)->toBe($type)
        ->and($column->attributes)->toBe($attributes)
        ->and($column->nullable)->toBeFalse()
        ->and($column->hasDefault)->toBeFalse()
        ->and($column->fidelity)->toBe(Fidelity::Reduced)
        ->and($result->diagnostics)->toHaveCount(0);
})->with([
    ['uuid()', 'uuid', 'uuid', []],
    ['uuid("token")', 'token', 'uuid', []],
    ['ulid()', 'ulid', 'ulid', []],
    ['ulid("ref", 32)', 'ref', 'ulid', ['length' => 32]],
    ['ipAddress()', 'ip_address', 'ipAddress', []],
    ['macAddress("hw")', 'hw', 'macAddress', []],
    ['json("meta")', 'meta', 'json', []],
    ['jsonb("meta")', 'meta', 'jsonb', []],
    ['date("born")', 'born', 'date', []],
    ['time("alarm", 3)', 'alarm', 'time', ['precision' => 3]],
    ['dateTime("seen", 3)', 'seen', 'dateTime', ['precision' => 3]],
    ['char("code", 4)', 'code', 'char', ['length' => 4]],
    ['char("code")', 'code', 'char', []],
    ['tinyText("note")', 'note', 'tinyText', []],
    ['mediumText("body")', 'body', 'mediumText', []],
    ['longText("log")', 'log', 'longText', []],
    ['float("score", 10)', 'score', 'float', ['precision' => 10]],
    ['double("score")', 'score', 'double', []],
    ['decimal("amount")', 'amount', 'decimal', []],
    ['decimal("amount", 10, 4)', 'amount', 'decimal', ['total' => 10, 'places' => 4]],
    ['enum("status", ["draft", "ready"])', 'status', 'enum', ['allowedValues' => ['draft', 'ready']]],
    ['set("tags", ["a", "b"])', 'tags', 'set', ['allowedValues' => ['a', 'b']]],
    ['year("born")', 'born', 'year', []],
    ['bigInteger("views")', 'views', 'bigInteger', ['autoIncrement' => false, 'unsigned' => false]],
    ['bigInteger("views", true, true)', 'views', 'bigInteger', ['autoIncrement' => true, 'unsigned' => true]],
    ['unsignedInteger("rank", true)', 'rank', 'unsignedInteger', ['autoIncrement' => true]],
    ['unsignedBigInteger("bytes")', 'bytes', 'unsignedBigInteger', ['autoIncrement' => false]],
]);

it('records increment columns as generic auto-increment integer types at reduced fidelity', function (string $expression, string $type) {
    $result = NormalizationFixture::normalize('Schema::create("users", fn ($t) => $t->'.$expression.');');
    $column = $result->operations[0]->columns[0];

    expect($column->name)->toBe('key')
        ->and($column->type)->toBe($type)
        ->and($column->attributes)->toBe(['autoIncrement' => true, 'unsigned' => true, 'primary' => true])
        ->and($column->fidelity)->toBe(Fidelity::Reduced)
        ->and($result->diagnostics)->toHaveCount(0);
})->with([
    ['increments("key")', 'increments'],
    ['integerIncrements("key")', 'integerIncrements'],
    ['mediumIncrements("key")', 'mediumIncrements'],
    ['smallIncrements("key")', 'smallIncrements'],
    ['tinyIncrements("key")', 'tinyIncrements'],
    ['bigIncrements("key")', 'bigIncrements'],
]);

it('records the known column expansions at reduced fidelity', function (string $expression, array $names, string $type, array $attributes) {
    $result = NormalizationFixture::normalize('Schema::create("users", function ($t) { $t->'.$expression.'; });');
    $body = $result->operations[0]->operations;

    expect(array_map(fn ($operation) => $operation->column->name, $body))->toBe($names)
        ->and(array_map(fn ($operation) => $operation->column->type, $body))->toBe(array_fill(0, count($names), $type))
        ->and(array_map(fn ($operation) => $operation->column->nullable, $body))->toBe(array_fill(0, count($names), true))
        ->and(array_map(fn ($operation) => $operation->column->attributes, $body))->toBe(array_fill(0, count($names), $attributes))
        ->and(array_map(fn ($operation) => $operation->column->fidelity, $body))->toBe(array_fill(0, count($names), Fidelity::Reduced))
        ->and($result->diagnostics)->toHaveCount(0);
})->with([
    ['softDeletes()', ['deleted_at'], 'timestamp', []],
    ['softDeletes("purged_at", 3)', ['purged_at'], 'timestamp', ['precision' => 3]],
    ['softDeletesTz()', ['deleted_at'], 'timestampTz', []],
    ['rememberToken()', ['remember_token'], 'string', ['length' => 100]],
    ['timestampsTz()', ['created_at', 'updated_at'], 'timestampTz', []],
    ['timestampsTz(2)', ['created_at', 'updated_at'], 'timestampTz', ['precision' => 2]],
]);

it('rejects per-column modifiers on known expansions like timestamps does', function (string $expression) {
    $result = NormalizationFixture::normalize('Schema::table("users", function ($t) { $t->'.$expression.'; });');
    $unknown = $result->operations[0]->operations[0];

    expect($unknown)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($unknown->reason)->toBe(DiagnosticCode::UnresolvedUnknownMethod)
        ->and($unknown->attribution)->toBe(Attribution::Attributed);
})->with([
    ['softDeletes()->nullable()'],
    ['rememberToken()->default("x")'],
    ['timestampsTz()->nullable()'],
]);

it('applies supported and partial modifiers to tier two columns while staying reduced', function () {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->uuid("token")->nullable()->default("abc"));');
    $column = $result->operations[0]->operations[0]->column;

    expect($column->nullable)->toBeTrue()
        ->and($column->hasDefault)->toBeTrue()
        ->and($column->default)->toBe('abc')
        ->and($column->fidelity)->toBe(Fidelity::Reduced)
        ->and($result->diagnostics)->toHaveCount(0);
});

it('reduces tier one columns that declare partially supported modifiers', function (string $modifiers, array $attributes) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->string("name")->'.$modifiers.');');
    $column = $result->operations[0]->operations[0]->column;

    expect($column->fidelity)->toBe(Fidelity::Reduced)
        ->and($column->attributes)->toBe(['length' => null] + $attributes)
        ->and($result->diagnostics)->toHaveCount(0);
})->with([
    ['comment("hello")', ['comment' => 'hello']],
    ['unsigned()', ['unsigned' => true]],
    ['unsigned(false)', ['unsigned' => false]],
    ['after("email")', ['after' => 'email']],
    ['first()', ['first' => true]],
    ['charset("utf8mb4")', ['charset' => 'utf8mb4']],
    ['collation("unicode_ci")', ['collation' => 'unicode_ci']],
]);

it('records dynamic default markers without pretending a static value exists', function (string $modifiers, mixed $default, array $attributes) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->string("name")->'.$modifiers.');');
    $column = $result->operations[0]->operations[0]->column;

    expect($column->hasDefault)->toBe($modifiers !== 'useCurrentOnUpdate()')
        ->and($column->default)->toBe($default)
        ->and($column->fidelity)->toBe(Fidelity::Reduced)
        ->and($column->attributes)->toBe(['length' => null] + $attributes)
        ->and($result->diagnostics)->toHaveCount(0);
})->with([
    ['default(now())', '[dynamic]', []],
    ['default(DB::raw("NOW()"))', '[dynamic]', []],
    ['default(\Illuminate\Support\Facades\DB::raw("CURRENT_TIMESTAMP"))', '[dynamic]', []],
    ['useCurrent()', 'CURRENT_TIMESTAMP', []],
    ['useCurrentOnUpdate()', null, ['onUpdate' => 'CURRENT_TIMESTAMP']],
]);

it('keeps other dynamic defaults and malformed partial modifiers unresolved', function (string $expression, DiagnosticCode $code) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->'.$expression.');');
    $unknown = $result->operations[0]->operations[0];

    expect($unknown)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($unknown->reason)->toBe($code)
        ->and($result->diagnostics->items[0]->code)->toBe($code);
})->with([
    ['string("x")->default(config("db.now"))', DiagnosticCode::UnresolvedDynamicExpression],
    ['string("x")->default(new DateTime("now"))', DiagnosticCode::UnresolvedDynamicExpression],
    ['string("x")->useCurrent("yes")', DiagnosticCode::UnresolvedDynamicExpression],
    ['string("x")->after(3)', DiagnosticCode::UnresolvedDynamicExpression],
    ['string("x")->after("")', DiagnosticCode::UnresolvedDynamicExpression],
    ['string("x")->comment(7)', DiagnosticCode::UnresolvedDynamicExpression],
    ['string("x")->change(false)', DiagnosticCode::UnresolvedDynamicExpression],
    ['string("x")->change("yes")', DiagnosticCode::UnresolvedDynamicExpression],
]);

it('records an explicit false dynamic default as a reduced no-op without a marker', function () {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->string("name")->useCurrent(false));');
    $column = $result->operations[0]->operations[0]->column;

    expect($column->hasDefault)->toBeFalse()
        ->and($column->default)->toBeNull()
        ->and($column->fidelity)->toBe(Fidelity::Reduced)
        ->and($result->diagnostics)->toHaveCount(0);
});

it('captures change events with the declared definition at reduced fidelity', function (string $expression, string $type, array $attributes) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->'.$expression.');');
    $change = $result->operations[0]->operations[0];

    expect($change)->toBeInstanceOf(ChangeColumn::class)
        ->and($change->column->name)->toBe('name')
        ->and($change->column->type)->toBe($type)
        ->and($change->column->attributes)->toBe($attributes)
        ->and($change->column->fidelity)->toBe(Fidelity::Reduced)
        ->and($result->diagnostics)->toHaveCount(0);
})->with([
    ['string("name", 50)->nullable()->change()', 'string', ['length' => 50]],
    ['integer("name")->unsigned()->change()', 'integer', ['autoIncrement' => false, 'unsigned' => true]],
    ['uuid("name")->change()', 'uuid', []],
]);

it('keeps unsupported change roots unresolved without inventing a change event', function () {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->customType("name")->change());');
    $unknown = $result->operations[0]->operations[0];

    expect($unknown)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($unknown->reason)->toBe(DiagnosticCode::UnresolvedUnknownMethod)
        ->and($result->diagnostics->items[0]->code)->toBe(DiagnosticCode::UnresolvedUnknownMethod);
});

it('keeps invalid tier two arguments unresolved without capturing a column', function (string $expression) {
    $result = NormalizationFixture::normalize('Schema::table("users", function ($t) { $t->'.$expression.'; $t->boolean("ok"); });');
    $alter = $result->operations[0];
    $unknown = $alter->operations[0];

    expect($unknown)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($unknown->reason)->toBe(DiagnosticCode::UnresolvedDynamicExpression)
        ->and($alter->operations[1])->toBeInstanceOf(AddColumn::class)
        ->and($alter->operations[1]->column->name)->toBe('ok');
})->with([
    'uuid($name)',
    'uuid("")',
    'enum("status", $values)',
    'enum("status", [])',
    'enum("status", ["a" => "b"])',
    'char("code", 0)',
    'char("code", $length)',
    'dateTime("seen", -1)',
    'decimal("amount", 8.5)',
    'bigInteger("views", "yes")',
    'bigIncrements($name)',
    'softDeletes($column)',
]);

it('exposes the implemented tier two registry rows', function () {
    $matrix = new SupportMatrix;
    $entry = $matrix->find('column', 'uuid');

    expect($entry)->not->toBeNull()
        ->and($entry->implemented)->toBeTrue()
        ->and($entry->fidelity)->toBe(Fidelity::Reduced)
        ->and((new OperationNormalizer)->normalize(NormalizationFixture::parse('Schema::table("users", fn ($t) => $t->uuid("token"));'))->operations[0]->operations[0])->toBeInstanceOf(AddColumn::class);
});
