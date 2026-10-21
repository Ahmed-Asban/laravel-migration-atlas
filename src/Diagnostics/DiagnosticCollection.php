<?php

declare(strict_types=1);

namespace MigrationAtlas\Diagnostics;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/** @implements IteratorAggregate<int, Diagnostic> */
final readonly class DiagnosticCollection implements Countable, IteratorAggregate
{
    /** @param list<Diagnostic> $items */
    public function __construct(public array $items = []) {}

    public function count(): int
    {
        return count($this->items);
    }

    /** @return ArrayIterator<int, Diagnostic> */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }
}
