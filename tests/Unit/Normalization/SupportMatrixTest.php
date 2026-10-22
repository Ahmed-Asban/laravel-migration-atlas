<?php

declare(strict_types=1);

use MigrationAtlas\Normalization\SupportLevel;
use MigrationAtlas\Normalization\SupportMatrix;
use MigrationAtlas\Operations\Fidelity;

it('registers every basic column and modifier as implemented at full fidelity', function (string $category, string $method) {
    $entry = (new SupportMatrix)->find($category, $method);

    expect($entry)->not->toBeNull()
        ->and($entry->tier)->toBe(1)
        ->and($entry->support)->toBe(SupportLevel::Supported)
        ->and($entry->fidelity)->toBe(Fidelity::Full)
        ->and($entry->implemented)->toBeTrue();
})->with([
    ['column', 'id'], ['column', 'string'], ['column', 'integer'], ['column', 'boolean'], ['column', 'text'], ['column', 'timestamps'],
    ['modifier', 'nullable'], ['modifier', 'default'],
]);

it('distinguishes the approved support contract from the currently implemented subset', function (string $category, string $method, SupportLevel $support, ?Fidelity $fidelity, bool $implemented) {
    $entry = (new SupportMatrix)->find($category, $method);

    expect($entry)->not->toBeNull()
        ->and($entry->support)->toBe($support)
        ->and($entry->fidelity)->toBe($fidelity)
        ->and($entry->implemented)->toBe($implemented);
})->with([
    ['schema', 'create', SupportLevel::Supported, Fidelity::Full, true],
    ['schema', 'createIfNotExists', SupportLevel::Deferred, null, false],
    ['schema', 'hasTable', SupportLevel::Supported, Fidelity::Full, false],
    ['lifecycle', 'dropColumn', SupportLevel::Supported, Fidelity::Full, true],
    ['lifecycle', 'renameColumn', SupportLevel::Supported, Fidelity::Full, true],
    ['column', 'increments', SupportLevel::Partial, Fidelity::Reduced, false],
    ['column', 'uuid', SupportLevel::Partial, Fidelity::Reduced, false],
    ['column', 'foreignId', SupportLevel::Partial, Fidelity::Reduced, false],
    ['column', 'foreignIdFor', SupportLevel::Partial, Fidelity::Reduced, false],
    ['column', 'timestampsTz', SupportLevel::Partial, Fidelity::Reduced, false],
    ['modifier', 'useCurrent', SupportLevel::Partial, Fidelity::Reduced, false],
    ['modifier', 'change', SupportLevel::Partial, Fidelity::Reduced, false],
    ['modifier', 'storedAs', SupportLevel::Deferred, null, false],
    ['index', 'primary', SupportLevel::Supported, Fidelity::Full, false],
    ['index', 'dropSpatialIndex', SupportLevel::Supported, Fidelity::Full, false],
    ['foreign', 'foreignId->constrained', SupportLevel::Supported, Fidelity::Full, false],
    ['context', 'shouldRun', SupportLevel::Supported, Fidelity::Full, true],
    ['context', 'raw_sql', SupportLevel::Deferred, null, true],
    ['context', 'skipped_migration', SupportLevel::Deferred, null, true],
    ['context', 'down', SupportLevel::NotAnalyzed, null, true],
]);

it('has unique method keys and consistent fidelity across the complete registry', function () {
    $matrix = new SupportMatrix;
    $entries = $matrix->entries();
    $keys = [];

    foreach ($entries as $entry) {
        $keys[] = $entry->category.'::'.$entry->method;
        expect($matrix->find($entry->category, $entry->method))->toBe($entry)
            ->and($entry->fidelity)->toBe(match ($entry->support) {
                SupportLevel::Supported => Fidelity::Full,
                SupportLevel::Partial => Fidelity::Reduced,
                SupportLevel::Deferred, SupportLevel::NotAnalyzed => null,
            });
    }

    expect(count(array_unique($keys)))->toBe(count($entries))
        ->and($matrix->find('schema', 'customMacro'))->toBeNull()
        ->and($matrix->blueprint('customType'))->toBeNull()
        ->and($matrix->find('column', 'STRING')?->method)->toBe('string')
        ->and($matrix->find('modifier', 'Nullable'))->toBeNull();
});
