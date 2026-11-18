<?php

declare(strict_types=1);

namespace MigrationAtlas\Support;

use MigrationAtlas\Diagnostics\DiagnosticCollection;

/**
 * The history command result: the shared envelope, the resolved lineage
 * timeline payload (null for error envelopes), and the diagnostics.
 */
final readonly class HistoryResult implements AnalysisResult
{
    public function __construct(
        public AnalysisMeta $meta,
        public ?HistoryData $data,
        public DiagnosticCollection $diagnostics,
    ) {}

    public function meta(): AnalysisMeta
    {
        return $this->meta;
    }

    public function diagnostics(): DiagnosticCollection
    {
        return $this->diagnostics;
    }
}
