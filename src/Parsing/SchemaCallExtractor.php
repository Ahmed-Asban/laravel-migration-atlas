<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

final class SchemaCallExtractor
{
    private bool $afterExit = false;

    private bool $nonlocalExit = false;

    public function __construct(
        private readonly ExpressionEvaluator $evaluator = new ExpressionEvaluator,
        private readonly BlueprintCallAnalyzer $blueprints = new BlueprintCallAnalyzer,
    ) {}

    /** @return list<RawSchemaCall> */
    public function extract(Stmt\ClassMethod $up, string $source, ?string $connection): array
    {
        $calls = [];
        $this->afterExit = false;
        $this->nonlocalExit = false;

        foreach ($up->stmts ?? [] as $statement) {
            $this->walk($statement, $source, $connection, false, $calls);
        }

        return $calls;
    }

    /** @param list<RawSchemaCall> $calls */
    private function walk(Node $node, string $source, ?string $connection, bool $region, array &$calls, ?string $blueprint = null, bool $effectsOnly = false): void
    {
        // Definitions are not execution. Unknown calls that might invoke these
        // definitions are retained separately as unresolved side effects.
        if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_
            || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            return;
        }

        $region = $region || $this->afterExit || $this->isUnresolvedRegion($node);

        if ($node instanceof CallLike) {
            if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $blueprint !== null
                && $this->rootedOn($node, $blueprint)) {
                // The Blueprint analyzer owns this chain. Still find executed
                // SQL or Schema calls hidden inside the argument expressions.
                $this->walkChainArguments($node, $source, $connection, $region, $calls, $blueprint);

                return;
            }

            $call = $this->schemaCall($node, $source, $connection, $region);

            if ($call !== null) {
                $callback = ! $call->rawSql && in_array(strtolower($call->method ?? ''), ['create', 'table'], true)
                    ? $this->callback($node) : null;
                $argumentEffects = [];
                $this->argumentEffects($node, $source, $connection, $region, $argumentEffects, $callback, ! $effectsOnly);
                $nested = [];

                if ($callback !== null) {
                    $outerExit = $this->afterExit;
                    $outerNonlocalExit = $this->nonlocalExit;
                    $this->afterExit = false;
                    $this->nonlocalExit = false;
                    $parameter = $callback->params[0]->var ?? null;
                    $binding = $parameter instanceof Expr\Variable && is_string($parameter->name) ? $parameter->name : null;
                    $body = $callback instanceof Expr\Closure ? $callback->stmts : [$callback->expr];

                    foreach ($body as $statement) {
                        $this->walk($statement, $source, $connection, true, $nested, $binding);
                    }

                    $callbackAborts = $this->nonlocalExit;
                    $this->afterExit = $outerExit || $callbackAborts;
                    $this->nonlocalExit = $outerNonlocalExit || $callbackAborts;
                }

                // Grouped Blueprint calls cannot represent interleaved schema
                // mutations/SQL faithfully. Retain them as uncertain effects.
                array_push($calls, ...$argumentEffects);
                $calls[] = $nested === [] && ! $this->afterExit ? $call : new RawSchemaCall(
                    $call->method, $call->arguments, $call->line, $call->source,
                    $call->connection, $call->connectionExplicit, true, $call->rawSql,
                    $call->blueprintCalls, $call->callbackResolved,
                );
                array_push($calls, ...$nested);

                return;
            }

            // DB::raw builds an expression; it does not execute the SQL.
            if ($this->isDbExpression($node) || $effectsOnly) {
                $this->argumentEffects($node, $source, $connection, $region, $calls, reportOpaque: ! $effectsOnly);

                return;
            }

            if (! $node->isFirstClassCallable()) {
                $this->argumentEffects($node, $source, $connection, $region, $calls);
                $calls[] = $this->unknown($node, $source);
            }

            return;
        }

        if ($node instanceof Expr\Eval_ || $node instanceof Expr\Include_) {
            if (! $effectsOnly) {
                $calls[] = $this->unknown($node, $source);
            }

            return;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->$name;

            if ($child instanceof Node) {
                $this->walk($child, $source, $connection, $region, $calls, $blueprint, $effectsOnly);
            } elseif (is_array($child)) {
                foreach ($child as $item) {
                    if ($item instanceof Node) {
                        $this->walk($item, $source, $connection, $region, $calls, $blueprint, $effectsOnly);
                    }
                }
            }
        }

        if ($node instanceof Stmt\Return_ || $node instanceof Expr\Throw_
            || $node instanceof Expr\Exit_ || $node instanceof Stmt\Goto_ || $node instanceof Stmt\Break_ || $node instanceof Stmt\Continue_) {
            $this->afterExit = true;
        }

        if ($node instanceof Expr\Throw_ || $node instanceof Expr\Exit_) {
            $this->nonlocalExit = true;
        }
    }

    private function schemaCall(CallLike $node, string $source, ?string $migrationConnection, bool $region): ?RawSchemaCall
    {
        if (! $node instanceof Expr\StaticCall && ! $node instanceof Expr\MethodCall && ! $node instanceof Expr\NullsafeMethodCall) {
            return null;
        }

        $root = $node;
        $chain = [];

        while ($root instanceof Expr\MethodCall || $root instanceof Expr\NullsafeMethodCall) {
            $chain[] = $root;
            $root = $root->var;
        }

        if (! $root instanceof Expr\StaticCall || ! $root->class instanceof Name) {
            return null;
        }

        $schema = $this->isFacade($root->class, 'Schema');
        $database = $this->isFacade($root->class, 'DB');

        if (! $schema && ! $database) {
            return null;
        }

        $method = $node->name instanceof Identifier ? $node->name->toString() : null;
        $rootMethod = $root->name instanceof Identifier ? strtolower($root->name->toString()) : null;
        $connection = $migrationConnection;
        $explicit = false;
        $chain = array_reverse($chain);

        if ($chain !== [] && ! in_array($rootMethod, ['connection', 'getconnection'], true)) {
            return $this->unknown($node, $source);
        }

        if ($rootMethod === 'connection') {
            $explicit = true;
            $argument = $this->argument($root, 'name', 0);
            $value = $argument === null
                ? ($root->getRawArgs() === [] ? null : new Unresolvable('Unresolved connection argument'))
                : $this->evaluator->evaluate($argument->value);

            if (count($root->getRawArgs()) > 1) {
                $value = new Unresolvable('Unexpected connection arguments');
            }

            if (is_string($value)) {
                $connection = $value === '' || $value === '0' ? $migrationConnection : $value;
            } else {
                $connection = $value === null ? $migrationConnection : null;
            }

            // A standalone wrapper is not a schema mutation, but evaluating a
            // dynamic wrapper must not be mistaken for an analyzed no-op.
            if ($chain === []) {
                return $connection === null ? $this->unknown($node, $source) : null;
            }
        }

        $connectionReceiver = $database || $rootMethod === 'getconnection';

        foreach ($chain as $index => $part) {
            if ($part instanceof Expr\NullsafeMethodCall || $part->isFirstClassCallable()) {
                $region = true;
            }

            $partMethod = $part->name instanceof Identifier ? strtolower($part->name->toString()) : null;

            if ($index < count($chain) - 1 && $partMethod !== 'getconnection') {
                // A builder stored/passed through arbitrary methods is not a
                // statically resolved Schema::connection wrapper.
                return $this->unknown($node, $source);
            }

            $connectionReceiver = $connectionReceiver || $partMethod === 'getconnection';
        }

        // DB::raw is a literal-expression factory, not SQL execution.
        if ($database && $chain === [] && $rootMethod === 'raw') {
            return null;
        }

        $rawSql = $connectionReceiver && in_array(strtolower($method ?? ''), ['statement', 'unprepared', 'affectingstatement', 'select', 'selectone', 'scalar', 'cursor', 'insert', 'update', 'delete'], true);

        if ($connectionReceiver && ! $rawSql) {
            return $this->unknown($node, $source);
        }

        $needsCallback = $schema && ! $rawSql && in_array(strtolower($method ?? ''), ['create', 'table'], true);
        $callback = $needsCallback ? $this->callback($node) : null;
        $blueprints = $callback !== null ? $this->blueprints->analyze($callback, $source) : [];
        $callbackResolved = ! $needsCallback || $callback !== null;

        foreach ($node->getRawArgs() as $argument) {
            if (! $argument instanceof Arg || $argument->unpack) {
                $region = true;
            }
        }

        return new RawSchemaCall(
            $method,
            NodeHelpers::arguments($node->getRawArgs(), $source, $this->evaluator),
            NodeHelpers::line($node->name),
            NodeHelpers::source($node, $source),
            $connection,
            $explicit,
            $region || $node->isFirstClassCallable(),
            $rawSql,
            $blueprints,
            $callbackResolved,
        );
    }

    private function callback(CallLike $call): Expr\Closure|Expr\ArrowFunction|null
    {
        $argument = $this->argument($call, 'callback', 1);

        return $argument !== null && ($argument->value instanceof Expr\Closure || $argument->value instanceof Expr\ArrowFunction)
            ? $argument->value : null;
    }

    private function argument(CallLike $call, string $name, int $position): ?Arg
    {
        foreach ($call->getRawArgs() as $index => $argument) {
            if (! $argument instanceof Arg || $argument->unpack) {
                return null;
            }

            if (($argument->name !== null && $argument->name->toString() === $name)
                || ($argument->name === null && $index === $position)) {
                return $argument;
            }
        }

        return null;
    }

    private function isFacade(Name $name, string $facade): bool
    {
        $resolved = ltrim($name->toString(), '\\');

        return strcasecmp($resolved, 'Illuminate\\Support\\Facades\\'.$facade) === 0
            || ($name instanceof Name\FullyQualified && strcasecmp($resolved, $facade) === 0);
    }

    private function isDbExpression(CallLike $call): bool
    {
        return $call instanceof Expr\StaticCall && $call->class instanceof Name && $this->isFacade($call->class, 'DB')
            && $call->name instanceof Identifier && strtolower($call->name->toString()) === 'raw';
    }

    /** @param list<RawSchemaCall> $calls */
    private function argumentEffects(CallLike $call, string $source, ?string $connection, bool $region, array &$calls, Expr\Closure|Expr\ArrowFunction|null $callback = null, bool $reportOpaque = true): void
    {
        if (($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall) && $call->var instanceof CallLike) {
            $this->argumentEffects($call->var, $source, $connection, $region, $calls, reportOpaque: $reportOpaque);
        }

        foreach ($call->getRawArgs() as $argument) {
            if ($argument instanceof Arg && $argument->value !== $callback) {
                $this->walk($argument->value, $source, $connection, $region, $calls, effectsOnly: ! $reportOpaque);
            }
        }
    }

    private function rootedOn(Expr\MethodCall|Expr\NullsafeMethodCall $call, string $binding): bool
    {
        $root = $call->var;

        while ($root instanceof Expr\MethodCall || $root instanceof Expr\NullsafeMethodCall) {
            $root = $root->var;
        }

        return $root instanceof Expr\Variable && $root->name === $binding;
    }

    /** @param list<RawSchemaCall> $calls */
    private function walkChainArguments(Expr\MethodCall|Expr\NullsafeMethodCall $call, string $source, ?string $connection, bool $region, array &$calls, string $binding): void
    {
        if ($call->var instanceof Expr\MethodCall || $call->var instanceof Expr\NullsafeMethodCall) {
            $this->walkChainArguments($call->var, $source, $connection, $region, $calls, $binding);
        }

        foreach ($call->getRawArgs() as $argument) {
            if ($argument instanceof Arg) {
                $this->walk($argument->value, $source, $connection, $region, $calls, $binding, true);
            }
        }
    }

    private function unknown(Node $node, string $source): RawSchemaCall
    {
        return new RawSchemaCall(null, [], NodeHelpers::line($node), NodeHelpers::source($node, $source), null, unresolvedRegion: true, callbackResolved: false);
    }

    private function isUnresolvedRegion(Node $node): bool
    {
        return $node instanceof Stmt\If_ || $node instanceof Stmt\ElseIf_ || $node instanceof Stmt\Else_
            || $node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\While_ || $node instanceof Stmt\Do_
            || $node instanceof Stmt\Switch_ || $node instanceof Stmt\TryCatch || $node instanceof Stmt\Catch_ || $node instanceof Stmt\Finally_
            || $node instanceof Expr\Ternary || $node instanceof Expr\Match_
            || $node instanceof Expr\BinaryOp\BooleanAnd || $node instanceof Expr\BinaryOp\BooleanOr
            || $node instanceof Expr\BinaryOp\LogicalAnd || $node instanceof Expr\BinaryOp\LogicalOr
            || $node instanceof Expr\BinaryOp\Coalesce || $node instanceof Expr\NullsafeMethodCall;
    }
}
