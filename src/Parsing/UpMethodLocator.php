<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Unset_;

final class UpMethodLocator
{
    /** @param array<Stmt> $statements */
    public function locate(array $statements): ?MigrationContext
    {
        $candidates = $this->candidates($statements);

        if (count($candidates) !== 1) {
            return null;
        }

        $class = $candidates[0];

        return new MigrationContext(
            $class,
            $class->getMethod('up'),
            $class->getMethod('shouldRun') !== null,
            $this->connection($class),
        );
    }

    /**
     * Inspect declaration-level statements only: helpers and nested control flow
     * cannot be mistaken for the migration that Laravel would load.
     *
     * @param  array<Stmt>  $statements
     * @return list<Class_>
     */
    private function candidates(array $statements): array
    {
        $candidates = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Namespace_ || $statement instanceof Declare_) {
                array_push($candidates, ...$this->candidates($statement->stmts ?? []));

                continue;
            }

            $class = null;

            if ($statement instanceof Class_ && ! $statement->isAnonymous()) {
                $class = $statement;
            } elseif ($statement instanceof Return_ && $statement->expr instanceof New_ && $statement->expr->class instanceof Class_) {
                $class = $statement->expr->class;
            }

            if ($class !== null && $class->extends !== null && ($this->isDirectMigration($class) || $class->getMethod('up') !== null)) {
                $candidates[] = $class;
            }
        }

        return $candidates;
    }

    private function isDirectMigration(Class_ $class): bool
    {
        return $class->extends !== null
            && strtolower($class->extends->toString()) === 'illuminate\\database\\migrations\\migration';
    }

    private function connection(Class_ $class): ?string
    {
        if (! $this->isDirectMigration($class) || $class->getMethod('getConnection') !== null) {
            return null;
        }

        foreach ($class->getMethods() as $method) {
            foreach ($method->params as $parameter) {
                if (($parameter->flags !== 0 || $this->hasPropertyHooks($parameter))
                    && $parameter->var instanceof Variable && $parameter->var->name === 'connection') {
                    return null;
                }
            }
        }

        foreach ($class->stmts as $statement) {
            if ($statement instanceof ClassMethod && $statement->name->toLowerString() === 'down') {
                continue;
            }

            if ($statement instanceof TraitUse || $this->hasConnectionMutation($statement)) {
                return null;
            }
        }

        $value = 'default';
        $declarations = 0;

        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() !== 'connection') {
                    continue;
                }

                $declarations++;

                if ($declarations > 1 || $property->isPrivate() || $property->isStatic()
                    || $this->hasPropertyHooks($property)) {
                    return null;
                }

                if ($item->default === null || ($item->default instanceof ConstFetch && strtolower($item->default->name->toString()) === 'null')) {
                    $value = 'default';
                } elseif ($item->default instanceof String_) {
                    $value = $item->default->value === '' || $item->default->value === '0' ? 'default' : $item->default->value;
                } else {
                    return null;
                }
            }
        }

        return $value;
    }

    private function hasPropertyHooks(Node $node): bool
    {
        // php-parser 5.0 predates hook nodes, but remains a supported dependency.
        return property_exists($node, 'hooks') && $node->hooks !== [];
    }

    private function hasConnectionMutation(Node $node): bool
    {
        // Nested classes have their own $this; named functions have no bound $this.
        if ($node instanceof ClassLike || $node instanceof Function_) {
            return false;
        }

        // Once this instance escapes into an alias or opaque caller, writes
        // through that identity cannot be resolved without data-flow analysis.
        if ((($node instanceof Assign || $node instanceof AssignRef) && $this->containsThisIdentity($node->expr))
            || ($node instanceof Arg && $this->containsThisIdentity($node->value))
            || ($node instanceof Return_ && $node->expr !== null && $this->containsThisIdentity($node->expr))) {
            return true;
        }

        if (($node instanceof Assign || $node instanceof AssignRef || $node instanceof AssignOp
            || $node instanceof PreInc || $node instanceof PostInc || $node instanceof PreDec || $node instanceof PostDec)
            && $this->targetsConnection($node->var)) {
            return true;
        }

        // Taking a reference can mutate the property later through the alias.
        if ($node instanceof AssignRef && $this->targetsConnection($node->expr)) {
            return true;
        }

        if ($node instanceof ArrayItem && $node->byRef && $this->targetsConnection($node->value)) {
            return true;
        }

        if ($node instanceof Foreach_ && ($this->targetsConnection($node->valueVar)
            || ($node->keyVar !== null && $this->targetsConnection($node->keyVar)))) {
            return true;
        }

        if ($node instanceof Unset_) {
            foreach ($node->vars as $variable) {
                if ($this->targetsConnection($variable)) {
                    return true;
                }
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};

            if ($child instanceof Node && $this->hasConnectionMutation($child)) {
                return true;
            }

            if (is_array($child)) {
                foreach ($child as $item) {
                    if ($item instanceof Node && $this->hasConnectionMutation($item)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function containsThisIdentity(Node $node): bool
    {
        if ($node instanceof Variable) {
            return $node->name === 'this' || ! is_string($node->name);
        }

        // Receiver/property reads and deferred definitions do not themselves
        // expose the receiver identity. Their actual argument/body writes are
        // inspected by hasConnectionMutation instead.
        if ($node instanceof Expr\CallLike || $node instanceof PropertyFetch || $node instanceof Expr\NullsafePropertyFetch
            || $node instanceof Expr\Clone_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof ClassLike) {
            return false;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->$name;

            if ($child instanceof Node && $this->containsThisIdentity($child)) {
                return true;
            }

            if (is_array($child)) {
                foreach ($child as $item) {
                    if ($item instanceof Node && $this->containsThisIdentity($item)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function targetsConnection(Expr $target): bool
    {
        if ($target instanceof ArrayDimFetch) {
            return $this->targetsConnection($target->var);
        }

        if ($target instanceof Array_ || $target instanceof List_) {
            foreach ($target->items as $item) {
                if ($item !== null && $this->targetsConnection($item->value)) {
                    return true;
                }
            }
        }

        if (! $target instanceof PropertyFetch || ! $target->var instanceof Variable || $target->var->name !== 'this') {
            return false;
        }

        // A dynamic property name could be connection; never evaluate it.
        return ! $target->name instanceof Identifier || $target->name->toString() === 'connection';
    }
}
