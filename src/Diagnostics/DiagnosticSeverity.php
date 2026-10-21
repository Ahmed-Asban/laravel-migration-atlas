<?php

declare(strict_types=1);

namespace MigrationAtlas\Diagnostics;

enum DiagnosticSeverity: string
{
    case Warning = 'warning';
    case Error = 'error';
}
