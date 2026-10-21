<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

final class BlueprintCallAnalyzer
{
    private string $source = '';

    private string $binding = '';

    private bool $tainted = false;

    private bool $afterExit = false;

    /** @var array<string, true> */
    private array $aliases = [];

    /** @var list<RawBlueprintCall> */
    private array $calls = [];

    public function __construct(private readonly ExpressionEvaluator $evaluator = new ExpressionEvaluator) {}

    /** @return list<RawBlueprintCall> */
    public function analyze(Expr\Closure|Expr\ArrowFunction $callback, string $source): array
    {
        $this->source = $source;
        $this->binding = '';
        $this->tainted = false;
        $this->afterExit = false;
        $this->aliases = [];
        $this->calls = [];

        $parameter = $callback->params[0] ?? null;

        if ($parameter === null || $parameter->variadic || ! $parameter->var instanceof Expr\Variable || ! is_string($parameter->var->name)
            || ($parameter->type !== null && (! $parameter->type instanceof Node\Name\FullyQualified
                || strcasecmp($parameter->type->toString(), 'Illuminate\\Database\\Schema\\Blueprint') !== 0))) {
            $this->unknown($callback);

            return $this->calls;
        }

        $this->binding = $parameter->var->name;

        if ($callback instanceof Expr\ArrowFunction) {
            $this->walk($callback->expr, false);
        } else {
            foreach ($callback->stmts as $statement) {
                $this->walk($statement, false);
            }
        }

        return $this->calls;
    }

    private function walk(Node $node, bool $unresolved): void
    {
        $unresolved = $unresolved || $this->afterExit;

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            if ($this->capturesBinding($node)) {
                $this->unknown($node);
            }

            return;
        }

        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod) {
            return;
        }

        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignRef) {
            if ($this->containsBinding($node->var)) {
                $this->unknown($node);
                $this->tainted = true;
            } else {
                $aliasesBinding = $this->containsBinding($node->expr);
                $this->walk($node->expr, $unresolved);

                if ($node->var instanceof Expr\Variable && is_string($node->var->name)
                    && $aliasesBinding) {
                    $this->aliases[$node->var->name] = true;
                }
            }

            return;
        }

        if ($node instanceof Expr\MethodCall) {
            $chain = [$node];
            $receiver = $node->var;

            while ($receiver instanceof Expr\MethodCall) {
                array_unshift($chain, $receiver);
                $receiver = $receiver->var;
            }

            if ($receiver instanceof Expr\Variable && $this->isBinding($receiver)) {
                if ($this->tainted || $receiver->name !== $this->binding || $node->isFirstClassCallable()) {
                    $this->unknown($node);

                    return;
                }

                foreach ($chain as $method) {
                    $unresolved = $unresolved || ! $method->name instanceof Node\Identifier || $method->isFirstClassCallable();
                }

                $this->calls[] = new RawBlueprintCall(
                    $this->method($chain[0]),
                    array_map($this->method(...), array_slice($chain, 1)),
                    $unresolved,
                );

                foreach ($chain as $method) {
                    foreach ($method->args as $argument) {
                        if ($argument instanceof Node\Arg && $this->containsBinding($argument->value)) {
                            $this->unknown($argument->value);
                            $this->tainted = true;
                        }
                    }
                }

                return;
            }
        }

        if ($node instanceof Expr && $this->containsBinding($node)) {
            $this->unknown($node);
            $this->tainted = true;

            return;
        }

        $controlFlow = $node instanceof Stmt\If_ || $node instanceof Stmt\ElseIf_ || $node instanceof Stmt\Else_
            || $node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_ || $node instanceof Stmt\Switch_ || $node instanceof Stmt\Case_
            || $node instanceof Stmt\TryCatch || $node instanceof Stmt\Catch_ || $node instanceof Stmt\Finally_;

        if (($node instanceof Stmt\Foreach_ && ($this->containsBinding($node->valueVar)
                || ($node->keyVar !== null && $this->containsBinding($node->keyVar))))
            || (($node instanceof Stmt\Unset_ || $node instanceof Stmt\Global_) && $this->containsBinding($node))) {
            $this->unknown($node);
            $this->tainted = true;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->$name;

            if ($child instanceof Node) {
                $this->walk($child, $unresolved || $controlFlow);
            } elseif (is_array($child)) {
                foreach ($child as $item) {
                    if ($item instanceof Node) {
                        $this->walk($item, $unresolved || $controlFlow);
                    }
                }
            }
        }

        if ($node instanceof Stmt\Return_ || $node instanceof Stmt\Break_ || $node instanceof Stmt\Continue_
            || $node instanceof Stmt\Goto_ || $node instanceof Expr\Throw_ || $node instanceof Expr\Exit_) {
            $this->afterExit = true;
        }
    }

    /** @param array<string, true> $shadowed */
    private function containsBinding(Node $node, array $shadowed = []): bool
    {
        if ($node instanceof Expr\Variable) {
            return ! is_string($node->name) || ($this->isBinding($node) && ! isset($shadowed[$node->name]));
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            return $this->capturesBinding($node, $shadowed);
        }

        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod) {
            return false;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->$name;

            if ($child instanceof Node && $this->containsBinding($child, $shadowed)) {
                return true;
            }

            if (is_array($child)) {
                foreach ($child as $item) {
                    if ($item instanceof Node && $this->containsBinding($item, $shadowed)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @param array<string, true> $shadowed */
    private function capturesBinding(Expr\Closure|Expr\ArrowFunction $callback, array $shadowed = []): bool
    {
        if ($callback instanceof Expr\Closure) {
            foreach ($callback->uses as $use) {
                if ($this->isBinding($use->var) && is_string($use->var->name) && ! isset($shadowed[$use->var->name])) {
                    return true;
                }
            }

            return false;
        }

        foreach ($callback->params as $parameter) {
            if ($parameter->var instanceof Expr\Variable && is_string($parameter->var->name)) {
                $shadowed[$parameter->var->name] = true;
            }
        }

        return $this->containsBinding($callback->expr, $shadowed);
    }

    private function isBinding(Expr\Variable $variable): bool
    {
        return is_string($variable->name)
            && ($variable->name === $this->binding || isset($this->aliases[$variable->name]));
    }

    private function method(Expr\MethodCall $method): RawMethodCall
    {
        return new RawMethodCall(
            $method->name instanceof Node\Identifier ? $method->name->toString() : null,
            NodeHelpers::arguments($method->args, $this->source, $this->evaluator),
            NodeHelpers::line($method->name),
            NodeHelpers::source($method, $this->source),
        );
    }

    private function unknown(Node $node): void
    {
        $this->calls[] = new RawBlueprintCall(
            new RawMethodCall(null, [], NodeHelpers::line($node), NodeHelpers::source($node, $this->source)),
            [],
            true,
        );
    }
}
