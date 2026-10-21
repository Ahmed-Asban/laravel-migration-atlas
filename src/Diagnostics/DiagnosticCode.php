<?php

declare(strict_types=1);

namespace MigrationAtlas\Diagnostics;

enum DiagnosticCode: string
{
    case NonStandardFilename = 'NON_STANDARD_FILENAME';
    case MigrationSyntaxError = 'MIGRATION_SYNTAX_ERROR';
    case MigrationParseFailure = 'MIGRATION_PARSE_FAILURE';
    case FileTooLarge = 'FILE_TOO_LARGE';
    case MigrationSourceUnreadable = 'MIGRATION_SOURCE_UNREADABLE';
    case MigrationClassNotFound = 'MIGRATION_CLASS_NOT_FOUND';
    case UpMethodMissing = 'UP_METHOD_MISSING';
    case ShouldRunDetected = 'SHOULD_RUN_DETECTED';
}
