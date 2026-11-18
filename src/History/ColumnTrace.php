<?php

declare(strict_types=1);

namespace MigrationAtlas\History;

use MigrationAtlas\Reconstruction\State\ColumnState;

/**
 * One resolved column identity chain: the state that currently holds the
 * identity, the chain's current name, every observed chain identity, and
 * the events that touched the chain in replay order.
 */
final readonly class ColumnTrace
{
    /**
     * @param  list<string>  $identities
     * @param  list<HistoryEvent>  $events
     */
    public function __construct(
        public ColumnState $holder,
        public string $currentName,
        public array $identities,
        public array $events,
    ) {}
}
