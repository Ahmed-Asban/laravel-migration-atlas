<?php

declare(strict_types=1);

namespace MigrationAtlas\History;

/**
 * One replayed schema event in global order. A conditional event records
 * source intent, not proof that the change occurred. Skip markers live in
 * the uncertainty ledger and never appear here. The involved column
 * identities support column-chain tracing; rename events list the source
 * name first and the target name second.
 */
final readonly class HistoryEvent
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public int $sequence,
        public ?string $date,
        public string $operation,
        public string $summary,
        public bool $conditional,
        public string $file,
        public ?int $line,
        public array $columns = [],
    ) {}
}
