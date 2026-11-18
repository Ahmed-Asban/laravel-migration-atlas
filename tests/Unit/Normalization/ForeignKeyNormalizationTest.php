<?php

declare(strict_types=1);

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Normalization\OperationNormalizer;
use MigrationAtlas\Operations\AddColumn;
use MigrationAtlas\Operations\AddForeignKey;
use MigrationAtlas\Operations\Attribution;
use MigrationAtlas\Operations\ChangeColumn;
use MigrationAtlas\Operations\DropForeignKey;
use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Operations\UnresolvedOperation;
use MigrationAtlas\Tests\Support\NormalizationFixture;

it('normalizes literal foreign key chains with convention names at full fidelity', function (string $chain, array $columns, string $table, array $referenced, ?string $onDelete, ?string $onUpdate) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->foreign("'.$columns[0].'")->'.$chain.');');
    $key = $result->operations[0]->operations[0]->key;

    expect($result->operations[0]->operations[0])->toBeInstanceOf(AddForeignKey::class)
        ->and($key->name)->toBe('users_'.implode('_', $columns).'_foreign')
        ->and($key->columns)->toBe($columns)
        ->and($key->referencedTable)->toBe($table)
        ->and($key->referencedColumns)->toBe($referenced)
        ->and($key->onDelete)->toBe($onDelete)
        ->and($key->onUpdate)->toBe($onUpdate)
        ->and($key->fidelity)->toBe(Fidelity::Full)
        ->and($result->diagnostics)->toHaveCount(0);
})->with([
    ['references("id")->on("members")', ['user_id'], 'members', ['id'], null, null],
    ['references("id")->on("members")->onDelete("cascade")', ['user_id'], 'members', ['id'], 'cascade', null],
    ['references("id")->on("members")->onUpdate("restrict")', ['user_id'], 'members', ['id'], null, 'restrict'],
    ['references(["id", "uuid"])->on("members")->onDelete("set null")->onUpdate("cascade")', ['user_id'], 'members', ['id', 'uuid'], 'set null', 'cascade'],
    ['references("id")->on("members")->cascadeOnDelete()', ['user_id'], 'members', ['id'], 'cascade', null],
    ['references("id")->on("members")->restrictOnDelete()', ['user_id'], 'members', ['id'], 'restrict', null],
    ['references("id")->on("members")->nullOnDelete()', ['user_id'], 'members', ['id'], 'set null', null],
    ['references("id")->on("members")->cascadeOnUpdate()', ['user_id'], 'members', ['id'], null, 'cascade'],
]);

it('normalizes composite and custom-named foreign key chains', function () {
    $result = NormalizationFixture::normalize('Schema::table("users", function ($t) {
    $t->foreign(["a", "b"], "custom_fk")->references(["x", "y"])->on("targets");
});');
    $key = $result->operations[0]->operations[0]->key;

    expect($key->name)->toBe('custom_fk')
        ->and($key->columns)->toBe(['a', 'b'])
        ->and($key->referencedColumns)->toBe(['x', 'y'])
        ->and($key->referencedTable)->toBe('targets')
        ->and($result->diagnostics)->toHaveCount(0);
});

it('keeps incomplete or unknown foreign key chains unresolved', function (string $expression, DiagnosticCode $code) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->'.$expression.');');
    $unknown = $result->operations[0]->operations[0];

    expect($unknown)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($unknown->reason)->toBe($code)
        ->and($unknown->table)->toBe('users')
        ->and($unknown->attribution)->toBe(Attribution::Attributed)
        ->and($result->diagnostics->items[0]->code)->toBe($code);
})->with([
    ['foreign("user_id")', DiagnosticCode::UnresolvedDynamicExpression],
    ['foreign("user_id")->references("id")', DiagnosticCode::UnresolvedDynamicExpression],
    ['foreign("user_id")->on("members")', DiagnosticCode::UnresolvedDynamicExpression],
    ['foreign($column)->references("id")->on("members")', DiagnosticCode::UnresolvedDynamicExpression],
    ['foreign("user_id")->references($column)->on("members")', DiagnosticCode::UnresolvedDynamicExpression],
    ['foreign("user_id")->references("id")->on($table)', DiagnosticCode::UnresolvedDynamicExpression],
    ['foreign("user_id")->references("id")->on("members")->deferrable()', DiagnosticCode::UnresolvedUnknownMethod],
    ['foreign("user_id")->constrained()', DiagnosticCode::UnresolvedUnknownMethod],
    ['foreign()->references("id")->on("members")', DiagnosticCode::UnresolvedDynamicExpression],
    ['foreign("user_id")->references()->on("members")', DiagnosticCode::UnresolvedDynamicExpression],
    ['foreign("user_id")->references("id", "idx", "extra")->on("members")', DiagnosticCode::UnresolvedDynamicExpression],
]);

it('normalizes foreign key drops by name and by column list', function (string $expression, string $name, array $columns) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->'.$expression.');');
    $drop = $result->operations[0]->operations[0];

    expect($drop)->toBeInstanceOf(DropForeignKey::class)
        ->and($drop->name)->toBe($name)
        ->and($drop->columns)->toBe($columns)
        ->and($result->diagnostics)->toHaveCount(0);
})->with([
    ['dropForeign("users_user_id_foreign")', 'users_user_id_foreign', []],
    ['dropForeign(["user_id"])', 'users_user_id_foreign', ['user_id']],
    ['dropForeign(["a", "b"])', 'users_a_b_foreign', ['a', 'b']],
]);

it('keeps malformed foreign key drops unresolved', function (string $expression) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->'.$expression.');');
    $unknown = $result->operations[0]->operations[0];

    expect($unknown)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($unknown->reason)->toBe(DiagnosticCode::UnresolvedDynamicExpression);
})->with([
    'dropForeign()',
    'dropForeign($index)',
    'dropForeign("")',
    'dropForeign([])',
    'dropForeign([""])',
]);

it('records a bare foreignId column without inventing a foreign key', function () {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->foreignId("user_id"));');
    $operations = $result->operations[0]->operations;

    expect($operations)->toHaveCount(1)
        ->and($operations[0])->toBeInstanceOf(AddColumn::class)
        ->and($operations[0]->column->name)->toBe('user_id')
        ->and($operations[0]->column->type)->toBe('foreignId')
        ->and($operations[0]->column->attributes)->toBe(['unsigned' => true])
        ->and($operations[0]->column->fidelity)->toBe(Fidelity::Reduced)
        ->and($result->diagnostics)->toHaveCount(0);
});

it('derives the constrained family with Laravel conventions at full fidelity', function (string $chain, string $name, string $table, array $referenced, ?string $onDelete, ?string $onUpdate) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->foreignId("user_id")->'.$chain.');');
    $operations = $result->operations[0]->operations;

    expect($operations)->toHaveCount(2)
        ->and($operations[0])->toBeInstanceOf(AddColumn::class)
        ->and($operations[1])->toBeInstanceOf(AddForeignKey::class)
        ->and($operations[1]->key->name)->toBe($name)
        ->and($operations[1]->key->columns)->toBe(['user_id'])
        ->and($operations[1]->key->referencedTable)->toBe($table)
        ->and($operations[1]->key->referencedColumns)->toBe($referenced)
        ->and($operations[1]->key->onDelete)->toBe($onDelete)
        ->and($operations[1]->key->onUpdate)->toBe($onUpdate)
        ->and($operations[1]->key->fidelity)->toBe(Fidelity::Full)
        ->and($result->diagnostics)->toHaveCount(0);
})->with([
    ['constrained()', 'users_user_id_foreign', 'users', ['id'], null, null],
    ['constrained()->cascadeOnDelete()', 'users_user_id_foreign', 'users', ['id'], 'cascade', null],
    ['constrained()->restrictOnDelete()', 'users_user_id_foreign', 'users', ['id'], 'restrict', null],
    ['constrained()->nullOnDelete()->cascadeOnUpdate()', 'users_user_id_foreign', 'users', ['id'], 'set null', 'cascade'],
    ['constrained("teams")', 'users_user_id_foreign', 'teams', ['id'], null, null],
    ['constrained("teams", "uuid")', 'users_user_id_foreign', 'teams', ['uuid'], null, null],
    ['constrained(indexName: "fk_custom")', 'fk_custom', 'users', ['id'], null, null],
    ['references("uuid")->on("teams")', 'users_user_id_foreign', 'teams', ['uuid'], null, null],
    ['references("uuid", "fk_alt")->on("teams")', 'fk_alt', 'teams', ['uuid'], null, null],
]);

it('pluralizes constrained convention tables like Laravel does', function (string $column, string $table) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->foreignId("'.$column.'")->constrained());');
    $key = $result->operations[0]->operations[1]->key;

    expect($key->referencedTable)->toBe($table)
        ->and($key->name)->toBe('users_'.$column.'_foreign')
        ->and($key->referencedColumns)->toBe(['id'])
        ->and($result->diagnostics)->toHaveCount(0);
})->with([
    ['category_id', 'categories'],
    ['person_id', 'people'],
    ['child_id', 'children'],
    ['team_member_id', 'team_members'],
]);

it('keeps dead foreign key attributes before constrained or references unresolved', function (string $expression, DiagnosticCode $code) {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->foreignId("user_id")->'.$expression.');');
    $unknown = $result->operations[0]->operations[0];

    expect($unknown)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($unknown->reason)->toBe($code)
        ->and($result->diagnostics->items[0]->code)->toBe($code);
})->with([
    ['onDelete("cascade")', DiagnosticCode::UnresolvedUnknownMethod],
    ['on("teams")', DiagnosticCode::UnresolvedUnknownMethod],
    ['cascadeOnDelete()', DiagnosticCode::UnresolvedUnknownMethod],
    ['cascadeOnDelete(true)', DiagnosticCode::UnresolvedUnknownMethod],
]);

it('interleaves column modifiers with foreign key chain modifiers in declared order', function () {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->foreignId("user_id")->nullable()->constrained()->cascadeOnDelete());');
    $operations = $result->operations[0]->operations;

    expect($operations)->toHaveCount(2)
        ->and($operations[0]->column->nullable)->toBeTrue()
        ->and($operations[1]->key->onDelete)->toBe('cascade')
        ->and($result->diagnostics)->toHaveCount(0);
});

it('records foreignIdFor columns with convention attributes at reduced fidelity', function () {
    $source = '<?php
use App\Models\User;
use Illuminate\Support\Facades\Schema;

return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::table("orders", function ($t) {
            $t->foreignIdFor(User::class);
        });
    }
};';
    $result = (new OperationNormalizer)->normalize(NormalizationFixture::source($source));
    $operations = $result->operations[0]->operations;

    expect($operations)->toHaveCount(1)
        ->and($operations[0])->toBeInstanceOf(AddColumn::class)
        ->and($operations[0]->column->name)->toBe('user_id')
        ->and($operations[0]->column->type)->toBe('foreignIdFor')
        ->and($operations[0]->column->attributes)->toBe(['references' => 'id', 'table' => 'users', 'unsigned' => true])
        ->and($operations[0]->column->fidelity)->toBe(Fidelity::Reduced)
        ->and($result->diagnostics)->toHaveCount(0);
});

it('derives foreignIdFor constrained keys at reduced fidelity', function () {
    $source = '<?php
use App\Models\Category;
use Illuminate\Support\Facades\Schema;

return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::table("orders", function ($t) {
            $t->foreignIdFor(Category::class, "cat_id")->constrained()->cascadeOnDelete();
        });
    }
};';
    $result = (new OperationNormalizer)->normalize(NormalizationFixture::source($source));
    $operations = $result->operations[0]->operations;

    expect($operations)->toHaveCount(2)
        ->and($operations[0]->column->name)->toBe('cat_id')
        ->and($operations[1])->toBeInstanceOf(AddForeignKey::class)
        ->and($operations[1]->key->columns)->toBe(['cat_id'])
        ->and($operations[1]->key->name)->toBe('orders_cat_id_foreign')
        ->and($operations[1]->key->referencedTable)->toBe('categories')
        ->and($operations[1]->key->referencedColumns)->toBe(['id'])
        ->and($operations[1]->key->onDelete)->toBe('cascade')
        ->and($operations[1]->key->fidelity)->toBe(Fidelity::Reduced)
        ->and($result->diagnostics)->toHaveCount(0);
});

it('keeps unresolved or dynamic model identities without fabricating conventions', function (string $expression) {
    $result = NormalizationFixture::normalize('Schema::table("orders", fn ($t) => $t->'.$expression.');');
    $unknown = $result->operations[0]->operations[0];

    expect($unknown)->toBeInstanceOf(UnresolvedOperation::class)
        ->and($unknown->reason)->toBe(DiagnosticCode::UnresolvedDynamicExpression)
        ->and($result->diagnostics->items[0]->code)->toBe(DiagnosticCode::UnresolvedDynamicExpression);
})->with([
    'foreignIdFor($model)',
    'foreignIdFor(new User)',
    'foreignIdFor(User::class, $column)',
    'foreignIdFor(User::class, "")',
]);

it('captures change events on foreignId columns while keeping the derived key', function () {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->foreignId("user_id")->constrained()->change());');
    $operations = $result->operations[0]->operations;

    expect($operations)->toHaveCount(2)
        ->and($operations[0])->toBeInstanceOf(ChangeColumn::class)
        ->and($operations[0]->column->name)->toBe('user_id')
        ->and($operations[0]->column->attributes)->toBe(['unsigned' => true])
        ->and($operations[0]->column->fidelity)->toBe(Fidelity::Reduced)
        ->and($operations[1])->toBeInstanceOf(AddForeignKey::class)
        ->and($operations[1]->key->referencedTable)->toBe('users');
});
