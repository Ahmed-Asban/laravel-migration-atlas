<?php

declare(strict_types=1);

namespace MigrationAtlas\Support;

use MigrationAtlas\History\HistoryEvent;
use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Operations\SourceLocation;
use MigrationAtlas\Reconstruction\State\ColumnState;
use MigrationAtlas\Reconstruction\State\Existence;
use MigrationAtlas\Reconstruction\State\ForeignKeyState;
use MigrationAtlas\Reconstruction\State\IncompletenessReason;
use MigrationAtlas\Reconstruction\State\IndexState;

/**
 * The resolved inspect payload. Columns keep their replayed states with
 * existence and provenance; presentation decides which identities to
 * render for live versus dropped tables.
 */
final readonly class InspectData
{
    /**
     * @param  list<string>  $possibleNames
     * @param  list<IncompletenessReason>  $reasons
     * @param  array<string, ColumnState>  $columns
     * @param  list<IndexState>  $indexes
     * @param  list<ForeignKeyState>  $foreignKeys
     * @param  list<HistoryEvent>  $historySummary
     */
    public function __construct(
        public string $name,
        public string $connection,
        public ResultStatus $status,
        public Existence $existence,
        public array $possibleNames,
        public bool $nameCertain,
        public array $reasons,
        public Fidelity $fidelity,
        public ?SourceLocation $createdBy,
        public ?SourceLocation $droppedBy,
        public array $columns,
        public array $indexes,
        public array $foreignKeys,
        public array $historySummary,
    ) {}
}
