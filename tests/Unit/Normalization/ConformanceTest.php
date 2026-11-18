<?php

declare(strict_types=1);

use MigrationAtlas\Normalization\OperationNormalizer;
use MigrationAtlas\Normalization\SupportLevel;
use MigrationAtlas\Normalization\SupportMatrix;
use MigrationAtlas\Operations\AddColumn;
use MigrationAtlas\Operations\CreateTable;
use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Operations\UnresolvedOperation;
use MigrationAtlas\Tests\Support\NormalizationFixture;

it('keeps the registry aligned with the approved support contract', function (string $category, string $method) {
    $entry = (new SupportMatrix)->find($category, $method);

    expect($entry)->not->toBeNull();

    // Every SUPPORTED construct is implemented at full fidelity, every
    // PARTIAL construct is implemented at reduced fidelity, and the
    // fidelity axis never crosses the support classification.
    $expectedFidelity = match ($entry->support) {
        SupportLevel::Supported => Fidelity::Full,
        SupportLevel::Partial => Fidelity::Reduced,
        SupportLevel::Deferred, SupportLevel::NotAnalyzed => null,
    };

    expect($entry->fidelity)->toBe($expectedFidelity)
        ->and($entry->implemented)->toBe($entry->support === SupportLevel::Supported || $entry->support === SupportLevel::Partial
            ? true
            : $entry->implemented);
})->with(function () {
    $matrix = new SupportMatrix;
    $cases = [];

    foreach ($matrix->entries() as $entry) {
        $cases[$entry->category.'::'.$entry->method] = [$entry->category, $entry->method];
    }

    return $cases;
});

it('normalizes every supported row without unresolved markers at full fidelity', function (string $expression) {
    $result = NormalizationFixture::normalize('Schema::create("users", function ($t) { $t->'.$expression.'; });');
    $operations = $result->operations[0]->operations;

    foreach ($operations as $operation) {
        expect($operation)->toBeInstanceOf(AddColumn::class)
            ->and($operation->column->fidelity)->toBe(Fidelity::Full);
    }

    expect($result->diagnostics)->toHaveCount(0);
})->with([
    'id()', 'id("key")', 'string("name")', 'string("name", 80)', 'integer("rank")',
    'boolean("active")', 'text("bio")', 'timestamps()', 'timestamps(3)',
]);

it('normalizes every partially supported row at reduced fidelity without unresolved markers', function (string $expression) {
    $result = NormalizationFixture::normalize('Schema::create("users", function ($t) { $t->'.$expression.'; });');
    $operations = $result->operations[0]->operations;

    foreach ($operations as $operation) {
        expect($operation)->toBeInstanceOf(AddColumn::class)
            ->and($operation->column->fidelity)->toBe(Fidelity::Reduced);
    }

    expect($result->diagnostics)->toHaveCount(0);
})->with([
    'uuid("token")', 'ulid("ref")', 'ipAddress()', 'macAddress()', 'json("meta")',
    'jsonb("meta")', 'date("born")', 'time("alarm")', 'dateTime("seen")', 'char("code", 4)',
    'tinyText("note")', 'mediumText("body")', 'longText("log")', 'float("score", 10)',
    'double("score")', 'decimal("amount", 10, 4)', 'enum("status", ["draft", "ready"])',
    'set("tags", ["a"])', 'year("born")', 'bigInteger("views")', 'unsignedInteger("rank")',
    'unsignedBigInteger("bytes")', 'bigIncrements("key")', 'increments("key")',
    'softDeletes()', 'softDeletesTz()', 'rememberToken()', 'timestampsTz()',
    'foreignId("user_id")',
]);

it('keeps supported columns reduced when they declare partially supported modifiers', function () {
    $result = NormalizationFixture::normalize('Schema::table("users", fn ($t) => $t->string("name")->comment("free text"));');
    $column = $result->operations[0]->operations[0]->column;

    expect($column->fidelity)->toBe(Fidelity::Reduced)
        ->and($column->attributes['comment'])->toBe('free text')
        ->and($result->diagnostics)->toHaveCount(0);
});

it('keeps every deferred construct visibly unresolved', function (string $expression, string $code) {
    $result = NormalizationFixture::normalize('Schema::table("users", function ($t) { $t->'.$expression.'; });');

    expect($result->operations[0]->operations[0])->toBeInstanceOf(UnresolvedOperation::class)
        ->and($result->diagnostics->items[0]->code->value)->toBe($code);
})->with([
    ['string("x")->storedAs("computed")', 'UNRESOLVED_UNKNOWN_METHOD'],
    ['string("x")->virtualAs("computed")', 'UNRESOLVED_UNKNOWN_METHOD'],
    ['string("x")->generatedAs("computed")', 'UNRESOLVED_UNKNOWN_METHOD'],
]);

it('recognizes the exact guard shape after the hardening milestone', function () {
    $parsed = NormalizationFixture::parse('
        if (Schema::hasTable("users")) { return; }
        Schema::create("users", function ($t) { $t->id(); });');
    $result = (new OperationNormalizer)->normalize($parsed);

    expect($parsed->parsed)->toBeTrue()
        ->and($result->diagnostics)->toHaveCount(0)
        ->and($result->operations[0])->toBeInstanceOf(CreateTable::class);
});
