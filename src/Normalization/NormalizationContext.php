<?php

declare(strict_types=1);

namespace MigrationAtlas\Normalization;

use MigrationAtlas\Diagnostics\Diagnostic;
use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Diagnostics\DiagnosticSeverity;
use MigrationAtlas\Discovery\MigrationFile;
use MigrationAtlas\Operations\ExecutionCertainty;
use MigrationAtlas\Operations\SourceLocation;
use MigrationAtlas\Operations\UnresolvedOperation;

final class NormalizationContext
{
    private int $ordinal = 0;

    /** @param list<Diagnostic> $diagnostics */
    public function __construct(
        public readonly MigrationFile $file,
        public readonly ExecutionCertainty $execution,
        public array $diagnostics,
    ) {}

    public function source(?int $line): SourceLocation
    {
        return new SourceLocation($this->file->absolutePath, $this->file->name, $line, $this->file->ordinal, ++$this->ordinal);
    }

    /** @param list<string> $relatedTables */
    public function unresolved(
        ?int $line,
        string $raw,
        ?string $table,
        ?string $connection,
        DiagnosticCode $cause,
        array $relatedTables = [],
    ): UnresolvedOperation {
        $reason = $table === null || $connection === null ? DiagnosticCode::UnattributableOperation : $cause;
        $this->diagnostics[] = new Diagnostic($cause, DiagnosticSeverity::Warning, $this->message($cause), $this->file->absolutePath, $line);

        if ($reason !== $cause) {
            $this->diagnostics[] = new Diagnostic($reason, DiagnosticSeverity::Warning, $this->message($reason), $this->file->absolutePath, $line);
        }

        return new UnresolvedOperation($this->source($line), $this->execution, $table, $connection, $reason, $raw, relatedTables: $relatedTables, cause: $cause);
    }

    private function message(DiagnosticCode $code): string
    {
        return match ($code) {
            DiagnosticCode::RawSqlDetected => 'Raw SQL is detected but is not interpreted.',
            DiagnosticCode::UnresolvedConditionalRegion => 'Schema effects in this region cannot be normalized as certain operations.',
            DiagnosticCode::UnresolvedUnknownMethod => 'This method or call chain has no implemented normalization rule.',
            DiagnosticCode::UnattributableOperation => 'The table or connection target cannot be determined statically.',
            default => 'The arguments or callback cannot be resolved statically.',
        };
    }
}
