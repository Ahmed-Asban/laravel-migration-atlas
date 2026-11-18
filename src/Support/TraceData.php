<?php

declare(strict_types=1);

namespace MigrationAtlas\Support;

use MigrationAtlas\History\HistoryEvent;
use MigrationAtlas\Operations\SourceLocation;
use MigrationAtlas\Reconstruction\State\ColumnState;
use MigrationAtlas\Reconstruction\State\Existence;
use MigrationAtlas\Reconstruction\State\IncompletenessReason;

/**
 * The resolved trace payload. `exists` is true or false only for certain
 * presence or absence, null when unknown; current names are null when
 * uncertain. The definition is the candidate or last-known definition,
 * never a guaranteed current one.
 */
final readonly class TraceData
{
    /**
     * @param  list<string>  $possibleTableNames
     * @param  list<string>  $possibleColumnNames
     * @param  list<IncompletenessReason>  $reasons
     * @param  list<HistoryEvent>  $events
     */
    public function __construct(
        public string $requestedTable,
        public string $requestedColumn,
        public ?string $currentTable,
        public ?string $currentColumn,
        public ?bool $exists,
        public Existence $existence,
        public array $possibleTableNames,
        public array $possibleColumnNames,
        public array $reasons,
        public ?ColumnState $definition,
        public array $events,
        public ?SourceLocation $droppedBy,
    ) {}
}
