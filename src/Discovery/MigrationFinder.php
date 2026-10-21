<?php

declare(strict_types=1);

namespace MigrationAtlas\Discovery;

interface MigrationFinder
{
    /** @throws DiscoveryFailed */
    public function find(DiscoveryOptions $options): MigrationList;
}
