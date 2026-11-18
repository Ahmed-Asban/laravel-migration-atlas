<?php

declare(strict_types=1);

namespace MigrationAtlas\Support;

use MigrationAtlas\Diagnostics\DiagnosticCollection;

/**
 * The inspect command result: the shared envelope, the resolved data
 * payload (null for error envelopes), and the diagnostics.
 */
final readonly class InspectResult implements AnalysisResult
{
    public function __construct(
        public AnalysisMeta $meta,
        public ?InspectData $data,
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
