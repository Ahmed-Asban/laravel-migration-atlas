<?php

declare(strict_types=1);

use MigrationAtlas\Parsing\ExpressionEvaluator;
use MigrationAtlas\Parsing\Unresolvable;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

function literalExpressionForTest(string $expression, string $prefix = ''): Expr
{
    $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$prefix.' '.$expression.';');
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $nodes = $traverser->traverse($nodes ?? []);
    $statement = $nodes[array_key_last($nodes)];

    if ($statement instanceof Namespace_) {
        $statement = $statement->stmts[array_key_last($statement->stmts)];
    }

    if (! $statement instanceof Expression) {
        throw new LogicException('Expected an expression statement.');
    }

    return $statement->expr;
}

it('evaluates static scalar values without runtime resolution', function (string $expression, mixed $expected) {
    expect((new ExpressionEvaluator)->evaluate(literalExpressionForTest($expression)))->toBe($expected);
})->with([
    'string' => ["'name'", 'name'],
    'empty string' => ["''", ''],
    'integer' => ['123', 123],
    'zero' => ['0', 0],
    'negative integer' => ['-42', -42],
    'float' => ['12.5', 12.5],
    'negative float' => ['-0.25', -0.25],
    'positive numeric' => ['+42', 42],
    'true' => ['TRUE', true],
    'false' => ['false', false],
    'null' => ['NULL', null],
    'array' => ["['a', 2, false, null, ['nested' => -1.5]]", ['a', 2, false, null, ['nested' => -1.5]]],
    'empty array' => ['[]', []],
    'long array' => ["array('a' => 1)", ['a' => 1]],
    'keys and append' => ["[3 => 'a', 'b', 'name' => 'c', '3' => 'd']", [3 => 'd', 4 => 'b', 'name' => 'c']],
]);

it('resolves class literals using AST name resolution without loading their classes', function (string $prefix, string $expression, string $expected) {
    expect((new ExpressionEvaluator)->evaluate(literalExpressionForTest($expression, $prefix)))->toBe($expected);
})->with([
    'fully qualified' => ['', '\\Uninstalled\\Package\\Missing::class', 'Uninstalled\\Package\\Missing'],
    'aliased' => ['use App\\Models\\User as Account;', 'Account::class', 'App\\Models\\User'],
    'namespaced' => ['namespace App\\Migrations;', 'User::class', 'App\\Migrations\\User'],
    'group alias' => ['use App\\Models\\{User as Account};', 'Account::class', 'App\\Models\\User'],
]);

it('keeps dynamic and unsupported expressions visibly unresolvable', function (string $expression) {
    $value = (new ExpressionEvaluator)->evaluate(literalExpressionForTest($expression));

    expect($value)->toBeInstanceOf(Unresolvable::class)
        ->and($value->expression)->not->toBeEmpty();
})->with([
    '$name',
    'config("database.default")',
    'env("TABLE_NAME")',
    'now()',
    'DB::raw("CURRENT_TIMESTAMP")',
    'self::class',
    'parent::class',
    'static::class',
    '$class::class',
    'User::TABLE_NAME',
    'PHP_VERSION',
    '"a" . "b"',
    '1 + 2',
    '"hello $name"',
    '[1, $name]',
    '[...[]]',
    '[&$name]',
    '[false => "value"]',
    '[null => "value"]',
    '[1.5 => "value"]',
    '[$key => "value"]',
    '-"12"',
    '[9223372036854775807 => 1, 2]',
    '1e309',
    '-1e309',
    '[1e309]',
]);

it('does not resolve a class name when no name resolver supplied a qualified identity', function () {
    $expression = new Expr\ClassConstFetch(new Name('User'), 'class');

    expect((new ExpressionEvaluator)->evaluate($expression))->toBeInstanceOf(Unresolvable::class);
});

it('serializes unresolved values as explicit objects rather than null or strings', function () {
    $value = (new ExpressionEvaluator)->evaluate(literalExpressionForTest('$table'));

    expect(json_encode($value, JSON_THROW_ON_ERROR))->toBe('{"expression":"$table"}');
});
