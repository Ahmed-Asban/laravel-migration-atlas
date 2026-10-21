<?php

declare(strict_types=1);

namespace MigrationAtlas\Parsing;

use MigrationAtlas\Diagnostics\DiagnosticCode;
use RuntimeException;

final class SourceReadFailure extends RuntimeException
{
    public function __construct(public readonly DiagnosticCode $diagnostic, string $message)
    {
        parent::__construct($message);
    }
}
