<?php

declare(strict_types=1);

namespace MigrationAtlas\Support;

use MigrationAtlas\Diagnostics\DiagnosticCollection;

/**
 * Marker contract implemented by every command result DTO so renderers
 * and exit-code rules can treat them uniformly.
 */
interface AnalysisResult
{
    public function meta(): AnalysisMeta;

    public function diagnostics(): DiagnosticCollection;
}
