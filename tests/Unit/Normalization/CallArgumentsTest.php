<?php

declare(strict_types=1);

use MigrationAtlas\Normalization\CallArguments;
use MigrationAtlas\Parsing\RawArgument;
use MigrationAtlas\Parsing\Unresolvable;

it('binds positional and named arguments while preserving dynamic values', function () {
    $dynamic = new Unresolvable('$value');
    $arguments = [new RawArgument('users', null, false, 1, '"users"'), new RawArgument($dynamic, 'callback', false, 1, '$value')];

    expect(CallArguments::bind($arguments, ['table', 'callback'], 2))->toBe(['table' => 'users', 'callback' => $dynamic]);
});

it('rejects ambiguous bindings instead of selecting or discarding arguments', function (array $arguments) {
    expect(CallArguments::bind($arguments, ['table', 'callback'], 2))->toBeNull();
})->with([
    [[]],
    [[new RawArgument('users', null, false, 1, '')]],
    [[new RawArgument('users', null, false, 1, ''), new RawArgument(null, null, false, 1, ''), new RawArgument('extra', null, false, 1, '')]],
    [[new RawArgument('users', 'TABLE', false, 1, ''), new RawArgument(null, 'callback', false, 1, '')]],
    [[new RawArgument('users', 'table', false, 1, ''), new RawArgument(null, null, false, 1, '')]],
    [[new RawArgument('users', null, false, 1, ''), new RawArgument('duplicate', 'table', false, 1, '')]],
    [[new RawArgument([], null, true, 1, '')]],
]);
