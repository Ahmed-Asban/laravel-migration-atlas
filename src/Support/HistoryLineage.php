<?php

declare(strict_types=1);

namespace MigrationAtlas\Support;

use MigrationAtlas\History\HistoryEvent;
use MigrationAtlas\Operations\SourceLocation;
use MigrationAtlas\Reconstruction\State\Existence;
use MigrationAtlas\Reconstruction\State\IncompletenessReason;

/**
 * One lineage section of a history payload: its state axes and the events
 * that touched it, in replay order.
 */
final readonly class HistoryLineage
{
    /**
     * @param  list<string>  $possibleNames
     * @param  list<IncompletenessReason>  $reasons
     * @param  list<HistoryEvent>  $events
     */
    public function __construct(
        public int $lineageId,
        public string $name,
        public Existence $existence,
        public array $possibleNames,
        public bool $nameCertain,
        public array $reasons,
        public ?SourceLocation $createdBy,
        public ?SourceLocation $droppedBy,
        public array $events,
    ) {}
}
