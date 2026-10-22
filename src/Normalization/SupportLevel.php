<?php

declare(strict_types=1);

namespace MigrationAtlas\Normalization;

enum SupportLevel: string
{
    case Supported = 'supported';
    case Partial = 'partial';
    case Deferred = 'deferred';
    case NotAnalyzed = 'not_analyzed';
}
