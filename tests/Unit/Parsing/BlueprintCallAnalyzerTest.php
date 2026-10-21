<?php

declare(strict_types=1);

use MigrationAtlas\Parsing\BlueprintCallAnalyzer;
use MigrationAtlas\Parsing\RawBlueprintCall;
use MigrationAtlas\Parsing\Unresolvable;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** @return list<RawBlueprintCall> */
function analyzeBlueprintForTest(string $callback, string $prefix = '', ?BlueprintCallAnalyzer $analyzer = null): array
{
    $source = '<?php '.$prefix.' '.$callback.';';
    $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $nodes = $traverser->traverse($nodes ?? []);
    $statement = $nodes[array_key_last($nodes)];

    if (! $statement instanceof Expression || (! $statement->expr instanceof Expr\Closure && ! $statement->expr instanceof Expr\ArrowFunction)) {
        throw new LogicException('Expected a Blueprint closure.');
    }

    return ($analyzer ?? new BlueprintCallAnalyzer)->analyze($statement->expr, $source);
}

it('binds the actual first closure parameter and resolves Blueprint aliases', function (string $callback, string $prefix) {
    $calls = analyzeBlueprintForTest($callback, $prefix);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->rootCall->method)->toBe('string')
        ->and($calls[0]->rootCall->arguments[0]->value)->toBe('name')
        ->and($calls[0]->unresolvedRegion)->toBeFalse();
})->with([
    'untyped' => ['function ($bp) { $bp->string("name"); }', ''],
    'typed alias' => ['function (BP $fields) { $fields->string("name"); }', 'use Illuminate\\Database\\Schema\\Blueprint as BP;'],
    'fully qualified' => ['function (\\Illuminate\\Database\\Schema\\Blueprint $columns) { $columns->string("name"); }', ''],
    'untyped arrow' => ['fn ($columns) => $columns->string("name")', ''],
    'typed arrow' => ['fn (BP $columns) => $columns->string("name")', 'use Illuminate\\Database\\Schema\\Blueprint as BP;'],
]);

it('destructures fluent chains once and preserves source locations for each method', function () {
    $calls = analyzeBlueprintForTest(<<<'PHP'
function ($bp) {
    $bp->string('name', 80)
        ->nullable()
        ->default('guest');
    $bp->integer('age');
}
PHP);

    expect($calls)->toHaveCount(2)
        ->and($calls[0]->rootCall->method)->toBe('string')
        ->and($calls[0]->rootCall->line)->toBe(2)
        ->and($calls[0]->rootCall->source)->toBe('$bp->string(\'name\', 80)')
        ->and(array_column($calls[0]->modifiers, 'method'))->toBe(['nullable', 'default'])
        ->and(array_column($calls[0]->modifiers, 'line'))->toBe([3, 4])
        ->and($calls[0]->rootCall->arguments[1]->value)->toBe(80)
        ->and($calls[1]->rootCall->method)->toBe('integer')
        ->and($calls[1]->rootCall->line)->toBe(5);
});

it('preserves named dynamic and unpacked arguments as data', function () {
    $calls = analyzeBlueprintForTest('function ($bp) {
        $bp->string(column: "name", length: 80)->default($value);
        $bp->index(...["name", "email"]);
    }');

    expect($calls[0]->rootCall->arguments[0]->name)->toBe('column')
        ->and($calls[0]->rootCall->arguments[1]->name)->toBe('length')
        ->and($calls[0]->modifiers[0]->arguments[0]->value)->toBeInstanceOf(Unresolvable::class)
        ->and($calls[1]->rootCall->arguments[0]->unpack)->toBeTrue()
        ->and($calls[1]->rootCall->arguments[0]->value)->toBeInstanceOf(Unresolvable::class);
});

it('retains calls in unresolved control-flow regions without folding conditions', function (string $body) {
    $calls = analyzeBlueprintForTest('function ($bp) { '.$body.' }');
    $namedCalls = array_values(array_filter($calls, fn (RawBlueprintCall $call): bool => $call->rootCall->method !== null));

    expect($namedCalls)->not->toBeEmpty();

    foreach ($namedCalls as $call) {
        expect($call->unresolvedRegion)->toBeTrue();
    }
})->with([
    'if' => ['if (true) { $bp->string("name"); }'],
    'else' => ['if (false) {} else { $bp->string("name"); }'],
    'foreach' => ['foreach (["name"] as $column) { $bp->string($column); }'],
    'for' => ['for ($i = 0; $i < 1; $i++) { $bp->string("name"); }'],
    'while' => ['while (false) { $bp->string("name"); }'],
    'do' => ['do { $bp->string("name"); } while (false);'],
    'try catch finally' => ['try { $bp->string("name"); } catch (Throwable $e) { $bp->string("other"); } finally { $bp->string("last"); }'],
    'switch' => ['switch ($value) { case 1: $bp->string("name"); break; }'],
]);

it('keeps unsupported binding and dynamic forms visibly unresolved', function (string $callback) {
    $calls = analyzeBlueprintForTest($callback);

    expect($calls)->not->toBeEmpty();

    foreach ($calls as $call) {
        expect($call->unresolvedRegion)->toBeTrue();
    }
})->with([
    'no parameter' => ['function () { buildColumns(); }'],
    'wrong parameter type' => ['function (Other $bp) { $bp->string("name"); }'],
    'variadic parameter' => ['function (...$bp) { $bp->string("name"); }'],
    'alias' => ['function ($bp) { $alias = $bp; $alias->string("name"); }'],
    'reassignment' => ['function ($bp) { $bp = otherObject(); $bp->string("name"); }'],
    'reference assignment' => ['function ($bp) { $alias =& $bp; $alias->string("name"); }'],
    'helper' => ['function ($bp) { addFields($bp); }'],
    'nullsafe' => ['function ($bp) { $bp?->string("name"); }'],
    'dynamic method' => ['function ($bp) { $bp->{$method}("name"); }'],
    'variable variable' => ['function ($bp) { ${$receiver}->string("name"); }'],
    'dynamic modifier' => ['function ($bp) { $bp->string("name")->{$method}(); }'],
    'callable conversion' => ['function ($bp) { $method = $bp->string(...); }'],
    'short circuit' => ['function ($bp) { $flag && $bp->string("name"); }'],
    'ternary' => ['function ($bp) { $flag ? $bp->string("name") : null; }'],
]);

it('does not treat nested captured or shadowed scopes as direct Blueprint statements', function () {
    $calls = analyzeBlueprintForTest('function ($bp) {
        $nested = function ($bp) { $bp->string("shadowed"); };
        $captured = function () use ($bp) { $bp->string("captured"); };
        $arrow = fn () => $bp->string("arrow");
        $shadowedArrow = fn ($bp) => $bp->string("shadowed_arrow");
        function localHelper($bp) { $bp->string("function_scope"); }
        $bp->string("direct");
    }');

    expect($calls)->toHaveCount(3)
        ->and($calls[0]->rootCall->method)->toBeNull()
        ->and($calls[0]->rootCall->source)->toContain('captured')
        ->and($calls[1]->rootCall->method)->toBeNull()
        ->and($calls[1]->rootCall->source)->toContain('arrow')
        ->and($calls[2]->rootCall->method)->toBe('string')
        ->and($calls[2]->rootCall->arguments[0]->value)->toBe('direct')
        ->and($calls[2]->unresolvedRegion)->toBeFalse();
});

it('marks calls after an early return or throw as uncertain', function (string $exit) {
    $calls = analyzeBlueprintForTest('function ($bp) { '.$exit.' $bp->string("name"); }');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->rootCall->method)->toBe('string')
        ->and($calls[0]->unresolvedRegion)->toBeTrue();
})->with(['return;', 'if ($flag) { return; }', 'throw new RuntimeException;', 'exit;']);

it('ignores unrelated receivers and does not hardcode the name table', function () {
    $calls = analyzeBlueprintForTest('function ($bp, $table) { $table->string("unrelated"); $other->integer("also_unrelated"); $bp->id(); }');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->rootCall->method)->toBe('id');
});

it('keeps later calls on an assigned fluent result visibly unresolved', function () {
    $calls = analyzeBlueprintForTest('function ($bp) { $column = $bp->string("name"); $column->nullable(); }');

    expect($calls)->toHaveCount(2)
        ->and($calls[0]->rootCall->method)->toBe('string')
        ->and($calls[0]->unresolvedRegion)->toBeFalse()
        ->and($calls[1]->rootCall->method)->toBeNull()
        ->and($calls[1]->rootCall->source)->toBe('$column->nullable()')
        ->and($calls[1]->unresolvedRegion)->toBeTrue();
});

it('checks arrow captures independently when one of several tracked bindings is shadowed', function () {
    $calls = analyzeBlueprintForTest('function ($bp) {
        $column = $bp->string("name");
        $nested = fn ($column) => $bp->integer("captured");
    }');

    expect($calls)->toHaveCount(2)
        ->and($calls[0]->rootCall->method)->toBe('string')
        ->and($calls[1]->rootCall->method)->toBeNull()
        ->and($calls[1]->unresolvedRegion)->toBeTrue();
});

it('keeps verified empty callbacks empty and resets state between analyses', function () {
    $analyzer = new BlueprintCallAnalyzer;
    analyzeBlueprintForTest('function ($bp) { $bp = otherObject(); return; }', analyzer: $analyzer);

    expect(analyzeBlueprintForTest('function ($bp) {}', analyzer: $analyzer))->toBe([])
        ->and(analyzeBlueprintForTest('function ($bp) { $bp->id(); }', analyzer: $analyzer)[0]->unresolvedRegion)->toBeFalse();
});
