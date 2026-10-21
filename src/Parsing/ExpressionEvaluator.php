<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\PrettyPrinter\Standard;

final class ExpressionEvaluator
{
    public function evaluate(Expr $expression): mixed
    {
        if ($expression instanceof Scalar\String_ || $expression instanceof Scalar\Int_) {
            return $expression->value;
        }

        if ($expression instanceof Scalar\Float_) {
            return is_finite($expression->value) ? $expression->value : $this->unresolvable($expression);
        }

        if ($expression instanceof Expr\UnaryMinus || $expression instanceof Expr\UnaryPlus) {
            $value = $this->evaluate($expression->expr);

            if (is_int($value) || is_float($value)) {
                return $expression instanceof Expr\UnaryMinus ? -$value : $value;
            }
        }

        if ($expression instanceof Expr\ConstFetch) {
            return match (strtolower($expression->name->toString())) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => $this->unresolvable($expression),
            };
        }

        if ($expression instanceof Expr\ClassConstFetch
            && $expression->name instanceof Node\Identifier
            && strtolower($expression->name->toString()) === 'class'
            && $expression->class instanceof Node\Name\FullyQualified) {
            return $expression->class->toString();
        }

        if ($expression instanceof Expr\Array_) {
            return $this->evaluateArray($expression, $expression->items);
        }

        return $this->unresolvable($expression);
    }

    /**
     * Earlier php-parser v5 releases permit null items for array holes.
     *
     * @param  array<Node\ArrayItem|null>  $items
     * @return array<mixed>|Unresolvable
     */
    private function evaluateArray(Expr\Array_ $expression, array $items): array|Unresolvable
    {
        $result = [];

        foreach ($items as $item) {
            if ($item === null || $item->unpack || $item->byRef) {
                return $this->unresolvable($expression);
            }

            $value = $this->evaluate($item->value);

            if ($value instanceof Unresolvable) {
                return $this->unresolvable($expression);
            }

            if ($item->key === null) {
                // PHP rejects appending after the largest integer key.
                if (array_key_exists(PHP_INT_MAX, $result)) {
                    return $this->unresolvable($expression);
                }

                $result[] = $value;

                continue;
            }

            $key = $this->evaluate($item->key);

            // Avoid coercing booleans, null, floats or dynamic expressions into keys.
            if (! is_string($key) && ! is_int($key)) {
                return $this->unresolvable($expression);
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function unresolvable(Expr $expression): Unresolvable
    {
        return new Unresolvable((new Standard)->prettyPrintExpr($expression));
    }
}
