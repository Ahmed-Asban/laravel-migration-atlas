<?php

declare(strict_types=1);

namespace MigrationAtlas\Support;

use MigrationAtlas\Diagnostics\DiagnosticCollection;

/**
 * The trace command result: the shared envelope, the resolved column chain
 * payload (null for error envelopes), and the diagnostics.
 */
final readonly class TraceResult
{
    public function __construct(
        public AnalysisMeta $meta,
        public ?TraceData $data,
        public DiagnosticCollection $diagnostics,
    ) {}
}
