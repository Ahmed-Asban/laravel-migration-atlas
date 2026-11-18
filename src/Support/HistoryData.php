<?php

declare(strict_types=1);

namespace MigrationAtlas\Support;

use MigrationAtlas\Reconstruction\State\Existence;
use MigrationAtlas\Reconstruction\State\IncompletenessReason;

/**
 * The resolved history payload: every lineage that carried the requested
 * name, ordered earlier-dead-first with the primary identity last.
 * `currentName` is null when the primary identity's current name is
 * uncertain.
 */
final readonly class HistoryData
{
    /**
     * @param  list<string>  $possibleNames
     * @param  list<IncompletenessReason>  $reasons
     * @param  list<string>  $formerNames
     * @param  list<HistoryLineage>  $lineages
     */
    public function __construct(
        public string $table,
        public string $connection,
        public ?string $currentName,
        public Existence $existence,
        public array $possibleNames,
        public bool $nameCertain,
        public array $reasons,
        public array $formerNames,
        public array $lineages,
    ) {}
}
