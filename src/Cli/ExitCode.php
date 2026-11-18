<?php

declare(strict_types=1);

namespace MigrationAtlas\Cli;

use MigrationAtlas\Diagnostics\DiagnosticSeverity;
use MigrationAtlas\Reconstruction\State\TableStatus;
use MigrationAtlas\Support\AnalysisResult;

/**
 * Command exit codes. Errors always take precedence over strict
 * incompleteness; reduced fidelity never affects codes.
 */
enum ExitCode: int
{
    case Success = 0;

    case Failure = 1;

    case IncompleteStrict = 2;

    public static function resolve(AnalysisResult $result, bool $strict = false): self
    {
        foreach ($result->diagnostics()->items as $diagnostic) {
            if ($diagnostic->severity === DiagnosticSeverity::Error) {
                return self::Failure;
            }
        }

        if ($strict && $result->meta()->status === TableStatus::Incomplete) {
            return self::IncompleteStrict;
        }

        return self::Success;
    }
}
