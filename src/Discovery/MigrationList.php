<?php

declare(strict_types=1);

namespace MigrationAtlas\Discovery;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use MigrationAtlas\Diagnostics\DiagnosticCollection;

/** @implements IteratorAggregate<int, MigrationFile> */
final readonly class MigrationList implements Countable, IteratorAggregate
{
    /** @param list<MigrationFile> $files */
    public function __construct(
        public array $files,
        public DiagnosticCollection $diagnostics = new DiagnosticCollection,
    ) {}

    public function count(): int
    {
        return count($this->files);
    }

    /** @return ArrayIterator<int, MigrationFile> */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->files);
    }
}
