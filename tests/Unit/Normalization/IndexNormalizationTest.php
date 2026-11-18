<?php

declare(strict_types=1);

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Operations\AddIndex;
use MigrationAtlas\Operations\Attribution;
use MigrationAtlas\Operations\DropIndex;
use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Operations\UnresolvedOperation;
use MigrationAtlas\Tests\Support\NormalizationFixture;

it('normalizes every index construct with Laravel convention names', function (string $expression, string $type, array $columns, ?string $name) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->'.$expression.');');
    $index = $result->operations[0]->operations[0]->index;

    expect($result->operations[0]->operations[0])->toBeInstanceOf(AddIndex::class)
        ->and($index->type)->toBe($type)
        ->and($index->columns)->toBe($columns)
        ->and($index->name)->toBe($name ?? strtolower('users_'.implode('_', $columns).'_'.$type))
        ->and($index->fidelity)->toBe(Fidelity::Full)
        ->and($result->diagnostics)->toHaveCount(0);
})->with([
    ['primary("id")', 'primary', ['id'], null],
    ['unique("email")', 'unique', ['email'], null],
    ['index("col")', 'index', ['col'], null],
    ['fullText("body")', 'fulltext', ['body'], null],
    ['spatialIndex("location")', 'spatialindex', ['location'], null],
    ['unique(["email", "phone"])', 'unique', ['email', 'phone'], null],
    ['primary(["a", "b"])', 'primary', ['a', 'b'], null],
    ['index("col", "custom_name")', 'index', ['col'], 'custom_name'],
    ['unique(columns: ["Email"])', 'unique', ['Email'], null],
]);

it('applies Laravel lowercasing and separator replacement to convention names', function () {
    $result = NormalizationFixture::normalize('Schema::table("Users", fn ($t) => $t->index(["E-Mail", "code"]));');
    $index = $result->operations[0]->operations[0]->index;

    expect($index->name)->toBe('users_e_mail_code_index');
});

it('normalizes index drops by literal name, by column list, and conventionally', function (string $expression, string $type, ?string $name, array $columns) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->'.$expression.');');
    $drop = $result->operations[0]->operations[0];

    expect($drop)->toBeInstanceOf(DropIndex::class)
        ->and($drop->type)->toBe($type)
        ->and($drop->name)->toBe($name)
        ->and($drop->columns)->toBe($columns)
        ->and($result->diagnostics)->toHaveCount(0);
})->with([
    ['dropPrimary()', 'primary', null, []],
    ['dropPrimary(["a", "b"])', 'primary', 'users_a_b_primary', ['a', 'b']],
    ['dropPrimary("users_a_b_primary")', 'primary', 'users_a_b_primary', []],
    ['dropUnique("users_email_unique")', 'unique', 'users_email_unique', []],
    ['dropUnique(["email"])', 'unique', 'users_email_unique', ['email']],
    ['dropIndex(["col"])', 'index', 'users_col_index', ['col']],
    ['dropFullText("users_body_fulltext")', 'fulltext', 'users_body_fulltext', []],
    ['dropSpatialIndex(["location"])', 'spatialindex', 'users_location_spatialindex', ['location']],
]);

it('interleaves index operations with columns in source order', function () {
    $result = NormalizationFixture::normalize('Schema::create("users", function ($t) {
    $t->id();
    $t->string("email")->default("");
    $t->unique("email");
    $t->index(["name", "rank"]);
});');
    $create = $result->operations[0];

    expect($create->columns)->toHaveCount(2)
        ->and($create->operations[2])->toBeInstanceOf(AddIndex::class)
        ->and($create->operations[2]->index->name)->toBe('users_email_unique')
        ->and($create->operations[3])->toBeInstanceOf(AddIndex::class)
        ->and($create->operations[3]->index->name)->toBe('users_name_rank_index')
        ->and($create->operations[2]->source->position)->toBe([7, 4])
        ->and($create->operations[3]->source->position)->toBe([7, 5])
        ->and($result->diagnostics)->toHaveCount(0);
});

it('keeps malformed or dynamic index arguments unresolved', function (string $expression) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->'.$expression.');');
    $unknown = $result->operations[0]->operations[0];

    expect($unknown)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($unknown->reason)->toBe(DiagnosticCode::UnresolvedDynamicExpression)
        ->and($unknown->table)->toBe('users')
        ->and($unknown->connection)->toBe('default')
        ->and($unknown->attribution)->toBe(Attribution::Attributed);
})->with([
    'index($column)',
    'index(["a", $column])',
    'index(DB::raw("LOWER(email)"))',
    'index()',
    'index("")',
    'index([""])',
    'index([])',
    'index("col", "")',
    'index("col", $name)',
    'index("col", "name", "btree")',
    'index(...$columns)',
    'index(name: "named")',
    'dropUnique()',
    'dropUnique($index)',
    'dropUnique([""])',
    'dropUnique([])',
    'dropPrimary(["a"], "extra")',
]);

it('keeps unknown chained index modifiers unresolved', function () {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->unique("email")->deferrable());');
    $unknown = $result->operations[0]->operations[0];

    expect($unknown)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($unknown->reason)->toBe(DiagnosticCode::UnresolvedUnknownMethod)
        ->and($result->diagnostics->items[0]->code)->toBe(DiagnosticCode::UnresolvedUnknownMethod);
});
