<?php

declare(strict_types=1);

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Operations\AddColumn;
use MigrationAtlas\Operations\Attribution;
use MigrationAtlas\Operations\CreateTable;
use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Operations\UnresolvedOperation;
use MigrationAtlas\Tests\Support\NormalizationFixture;

it('normalizes each basic column with explicit semantics and provenance', function (string $expression, string $name, string $type, array $attributes) {
    $result = NormalizationFixture::normalize('Schema::create("users", function ($bp) { $bp->'.$expression.'; });');
    $create = $result->operations[0];
    $add = $create->operations[0];
    $column = $create->columns[0];

    expect($create)->toBeInstanceOf(CreateTable::class)
        ->and($add)->toBeInstanceOf(AddColumn::class)
        ->and($column)->toBe($add->column)
        ->and($column->name)->toBe($name)
        ->and($column->type)->toBe($type)
        ->and($column->attributes)->toBe($attributes)
        ->and($column->nullable)->toBeFalse()
        ->and($column->hasDefault)->toBeFalse()
        ->and($column->fidelity)->toBe(Fidelity::Full)
        ->and($column->source)->toBe($add->source)
        ->and($column->source->line)->toBe(7)
        ->and($column->source->position)->toBe([7, 2])
        ->and($result->diagnostics)->toHaveCount(0);
})->with([
    ['id()', 'id', 'bigint', ['unsigned' => true, 'autoIncrement' => true, 'primary' => true]],
    ['id("key")', 'key', 'bigint', ['unsigned' => true, 'autoIncrement' => true, 'primary' => true]],
    ['string("name")', 'name', 'string', ['length' => null]],
    ['string("name", 80)', 'name', 'string', ['length' => 80]],
    ['STRING(length: 90, column: "name")', 'name', 'string', ['length' => 90]],
    ['integer("age")', 'age', 'integer', ['autoIncrement' => false, 'unsigned' => false]],
    ['integer("seq", true, true)', 'seq', 'integer', ['autoIncrement' => true, 'unsigned' => true]],
    ['boolean("active")', 'active', 'boolean', []],
    ['text("bio")', 'bio', 'text', []],
]);

it('expands timestamps into two nullable columns in source order', function (string $expression, ?int $precision) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->'.$expression.');');
    $body = $result->operations[0]->operations;

    expect(array_map(fn ($op) => $op->column->name, $body))->toBe(['created_at', 'updated_at'])
        ->and(array_map(fn ($op) => $op->column->nullable, $body))->toBe([true, true])
        ->and(array_map(fn ($op) => $op->column->type, $body))->toBe(['timestamp', 'timestamp'])
        ->and(array_map(fn ($op) => $op->column->attributes, $body))->toBe([['precision' => $precision], ['precision' => $precision]])
        ->and(array_map(fn ($op) => $op->source->position, $body))->toBe([[7, 2], [7, 3]])
        ->and($result->diagnostics)->toHaveCount(0);
})->with([['timestamps()', null], ['timestamps(3)', 3], ['timestamps(precision: 0)', 0]]);

it('captures static defaults without confusing null with an absent default', function (string $literal, mixed $expected) {
    $result = NormalizationFixture::normalize('Schema::create("users", fn ($t) => $t->text("value")->default('.$literal.'));');
    $column = $result->operations[0]->columns[0];

    expect($column->hasDefault)->toBeTrue()
        ->and($column->default)->toBe($expected)
        ->and($column->fidelity)->toBe(Fidelity::Full)
        ->and($result->diagnostics)->toHaveCount(0);
})->with([
    ['"hello"', 'hello'], ['0', 0], ['-9', -9], ['1.25', 1.25], ['-0.5', -0.5],
    ['true', true], ['false', false], ['null', null], ['[]', []],
    ['["key" => [1, false, null], 5 => "five"]', ['key' => [1, false, null], 5 => 'five']],
    ['\DateTimeImmutable::class', 'DateTimeImmutable'],
]);

it('applies repeated nullable and default modifiers in declared order', function (string $modifiers, bool $nullable, mixed $default) {
    $result = NormalizationFixture::normalize('Schema::create("users", fn ($t) => $t->string("name")->'.$modifiers.');');
    $column = $result->operations[0]->columns[0];

    expect($column->nullable)->toBe($nullable)
        ->and($column->hasDefault)->toBeTrue()
        ->and($column->default)->toBe($default);
})->with([
    ['nullable()->default(null)', true, null],
    ['nullable(true)->default("x")', true, 'x'],
    ['nullable()->nullable(false)->default("x")->default("y")', false, 'y'],
]);

it('retains an invalid or unsupported column chain as uncertainty without inventing a column', function (string $expression, DiagnosticCode $code) {
    $result = NormalizationFixture::normalize('Schema::create("users", function ($t) { $t->'.$expression.'; $t->boolean("ok"); });');
    $create = $result->operations[0];
    $unknown = $create->operations[0];

    expect($create->columns)->toHaveCount(1)
        ->and($create->columns[0]->name)->toBe('ok')
        ->and($unknown)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($unknown->table)->toBe('users')
        ->and($unknown->connection)->toBe('default')
        ->and($unknown->attribution)->toBe(Attribution::Attributed)
        ->and($unknown->reason)->toBe($code)
        ->and($unknown->rawDescription)->toContain($expression)
        ->and($result->diagnostics->items[0]->code)->toBe($code);
})->with([
    ['id(null)', DiagnosticCode::UnresolvedDynamicExpression],
    ['string($name)', DiagnosticCode::UnresolvedDynamicExpression],
    ['string("x", $length)', DiagnosticCode::UnresolvedDynamicExpression],
    ['string("x", 0)', DiagnosticCode::UnresolvedDynamicExpression],
    ['string("x", 1.5)', DiagnosticCode::UnresolvedDynamicExpression],
    ['string("x", 10, 20)', DiagnosticCode::UnresolvedDynamicExpression],
    ['string(Column: "x")', DiagnosticCode::UnresolvedDynamicExpression],
    ['integer("x", null)', DiagnosticCode::UnresolvedDynamicExpression],
    ['integer("x", unsigned: $flag)', DiagnosticCode::UnresolvedDynamicExpression],
    ['boolean()', DiagnosticCode::UnresolvedDynamicExpression],
    ['text("")', DiagnosticCode::UnresolvedDynamicExpression],
    ['timestamps($precision)', DiagnosticCode::UnresolvedDynamicExpression],
    ['timestamps(-1)', DiagnosticCode::UnresolvedDynamicExpression],
    ['timestamps()->nullable()', DiagnosticCode::UnresolvedUnknownMethod],
    ['text("x")->nullable(1)', DiagnosticCode::UnresolvedDynamicExpression],
    ['text("x")->nullable(null)', DiagnosticCode::UnresolvedDynamicExpression],
    ['text("x")->default()', DiagnosticCode::UnresolvedDynamicExpression],
    ['text("x")->default(now())', DiagnosticCode::UnresolvedDynamicExpression],
    ['text("x")->default(DB::raw("NOW()"))', DiagnosticCode::UnresolvedDynamicExpression],
    ['text("x")->default([config("key")])', DiagnosticCode::UnresolvedDynamicExpression],
    ['text("x")->default(1e309)', DiagnosticCode::UnresolvedDynamicExpression],
    ['text("x")->default(...$values)', DiagnosticCode::UnresolvedDynamicExpression],
    ['text("x")->nullable(value: false)', DiagnosticCode::UnresolvedDynamicExpression],
    ['text("x")->Nullable()', DiagnosticCode::UnresolvedUnknownMethod],
    ['text("x")->comment("hello")', DiagnosticCode::UnresolvedUnknownMethod],
    ['text("x")->storedAs("computed")', DiagnosticCode::UnresolvedUnknownMethod],
    ['string("x")->change()', DiagnosticCode::UnresolvedUnknownMethod],
    ['string("x")->unique()', DiagnosticCode::UnresolvedUnknownMethod],
    ['uuid("x")', DiagnosticCode::UnresolvedUnknownMethod],
    ['foreignId("owner_id")->constrained()', DiagnosticCode::UnresolvedUnknownMethod],
    ['index(["x"], "idx")', DiagnosticCode::UnresolvedUnknownMethod],
    ['foreign("x")->references("id")->on("other")', DiagnosticCode::UnresolvedUnknownMethod],
    ['customType("x")', DiagnosticCode::UnresolvedUnknownMethod],
]);

it('uses the precise failing modifier line while retaining the entire chain', function () {
    $result = NormalizationFixture::normalize('Schema::table("users", function ($t) {
    $t->string("name")
        ->nullable()
        ->customModifier();
});');
    $unknown = $result->operations[0]->operations[0];

    expect($unknown->source->line)->toBe(10)
        ->and($unknown->rawDescription)->toContain('->string("name")', '->nullable()', '->customModifier()')
        ->and($result->diagnostics->items[0]->line)->toBe(10);
});

it('preserves binary literal bytes without executing or transcoding them', function () {
    $result = NormalizationFixture::normalize('Schema::create("users", fn ($t) => $t->text("value")->default("\xFF\x00"));');

    expect($result->operations[0]->columns[0]->default)->toBe("\xFF\x00");
});
