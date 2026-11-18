<?php

declare(strict_types=1);

namespace MigrationAtlas\Reconstruction;

use MigrationAtlas\Diagnostics\DiagnosticCode;

/**
 * One entry in the run-level uncertainty ledger: an analyzed unresolved
 * target or a skipped-file marker, in replay order.
 */
final readonly class LedgerEntry
{
    public function __construct(
        /** @var list{int, int} */
        public array $position,
        public string $file,
        public ?int $line,
        public string $kind,
        public ?string $table,
        public ?string $connection,
        public DiagnosticCode $reason,
    ) {}
}
