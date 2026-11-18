<?php

declare(strict_types=1);

namespace MigrationAtlas\History;

/**
 * One replayed schema event in global order. A conditional event records
 * source intent, not proof that the change occurred. Skip markers live in
 * the uncertainty ledger and never appear here.
 */
final readonly class HistoryEvent
{
    public function __construct(
        public int $sequence,
        public ?string $date,
        public string $operation,
        public string $summary,
        public bool $conditional,
        public string $file,
        public ?int $line,
    ) {}
}
