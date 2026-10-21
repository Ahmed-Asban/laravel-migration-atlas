<?php

declare(strict_types=1);

namespace MigrationAtlas\Diagnostics;

final readonly class Diagnostic
{
    public function __construct(
        public DiagnosticCode $code,
        public DiagnosticSeverity $severity,
        public string $message,
        public ?string $file = null,
        public ?int $line = null,
    ) {}
}
