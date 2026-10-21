<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

use PhpParser\Node;
use PhpParser\Node\Arg;

final class NodeHelpers
{
    public static function source(Node $node, string $source): string
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        return $start >= 0 && $end >= $start ? substr($source, $start, $end - $start + 1) : '';
    }

    public static function line(Node $node): ?int
    {
        $line = $node->getStartLine();

        return $line > 0 ? $line : null;
    }

    /**
     * @param  array<Node>  $arguments
     * @return list<RawArgument>
     */
    public static function arguments(array $arguments, string $source, ExpressionEvaluator $evaluator): array
    {
        $result = [];

        foreach ($arguments as $argument) {
            $result[] = $argument instanceof Arg
                ? new RawArgument(
                    $argument->unpack ? new Unresolvable(NodeHelpers::source($argument, $source)) : $evaluator->evaluate($argument->value),
                    $argument->name?->toString(),
                    $argument->unpack,
                    self::line($argument),
                    self::source($argument, $source),
                )
                : new RawArgument(new Unresolvable('First-class callable placeholder'), null, true, self::line($argument), self::source($argument, $source));
        }

        return $result;
    }
}
