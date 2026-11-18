<?php

declare(strict_types=1);

namespace MigrationAtlas\Reconstruction;

/**
 * The ordered uncertainty ledger: analyzed unresolved targets and skipped
 * files, retained even when no known lineage matched.
 */
final class UncertaintyLedger
{
    /** @var list<LedgerEntry> */
    private array $entries = [];

    public function add(LedgerEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<LedgerEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function hasSkippedMigrations(): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->kind === 'skipped_migration') {
                return true;
            }
        }

        return false;
    }

    /** @return list<LedgerEntry> */
    public function skippedMigrations(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (LedgerEntry $entry): bool => $entry->kind === 'skipped_migration',
        ));
    }

    /** @return list<LedgerEntry> */
    public function operations(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (LedgerEntry $entry): bool => $entry->kind === 'operation',
        ));
    }
}
