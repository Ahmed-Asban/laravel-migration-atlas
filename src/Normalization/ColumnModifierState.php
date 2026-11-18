<?php

declare(strict_types=1);

namespace MigrationAtlas\Normalization;

use MigrationAtlas\Operations\Fidelity;

/**
 * The reduced state of one column chain: declared modifiers translated into
 * static data. Partially supported modifiers keep the column's fidelity
 * reduced instead of pretending full semantics.
 */
final class ColumnModifierState
{
    public bool $nullable = false;

    public bool $hasDefault = false;

    public mixed $default = null;

    /** @var array<string, mixed> */
    public array $attributes = [];

    public bool $reduced = false;

    public bool $change = false;

    public function fidelity(): Fidelity
    {
        return $this->reduced ? Fidelity::Reduced : Fidelity::Full;
    }
}
