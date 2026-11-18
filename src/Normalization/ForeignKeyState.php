<?php

declare(strict_types=1);

namespace MigrationAtlas\Normalization;

/**
 * The accumulated state of one foreign-key fluent chain. A foreign() root
 * starts its own command; on a foreignId()/foreignIdFor() root only
 * constrained() or references() start one, mirroring the framework.
 */
final class ForeignKeyState
{
    /** @var list<string> */
    public ?array $columns = null;

    public ?string $name = null;

    /** @var list<string>|null */
    public ?array $referencedColumns = null;

    public ?string $referencedTable = null;

    public ?string $onDelete = null;

    public ?string $onUpdate = null;

    public bool $touched = false;

    public bool $reduced = false;
}
