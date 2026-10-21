<?php

declare(strict_types=1);

namespace MigrationAtlas\Discovery;

use RuntimeException;

final class DiscoveryFailed extends RuntimeException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct('Cannot enumerate migration directory: '.$path);
    }
}
