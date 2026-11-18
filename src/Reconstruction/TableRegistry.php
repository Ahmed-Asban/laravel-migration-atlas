<?php

declare(strict_types=1);

namespace MigrationAtlas\Reconstruction;

use MigrationAtlas\Reconstruction\State\TableState;

/**
 * The partition-aware registry of replayed table lineages. Partitions are
 * keyed by connection and never merge; lineages are identified by a stable
 * incrementing id. All mutations return a new registry.
 */
final readonly class TableRegistry
{
    /**
     * @param  array<string, array<int, TableState>>  $partitions
     */
    private function __construct(
        private array $partitions,
        private int $nextLineageId,
    ) {}

    public static function empty(): self
    {
        return new self([], 1);
    }

    /** @return list<TableState> */
    public function partition(string $connection): array
    {
        return array_values($this->partitions[$connection] ?? []);
    }

    /** @return array<string, list<TableState>> */
    public function partitions(): array
    {
        $partitions = [];

        foreach ($this->partitions as $connection => $lineages) {
            $partitions[$connection] = array_values($lineages);
        }

        return $partitions;
    }

    public function lineage(string $connection, int $lineageId): ?TableState
    {
        return $this->partitions[$connection][$lineageId] ?? null;
    }

    /**
     * Every lineage whose current, former, or statically observed possible
     * name matches in the given partition.
     *
     * @return list<TableState>
     */
    public function byName(string $connection, string $name): array
    {
        $matches = [];

        foreach ($this->partition($connection) as $lineage) {
            if ($lineage->name === $name
                || in_array($name, $lineage->formerNames, true)
                || in_array($name, $lineage->possibleNames, true)) {
                $matches[] = $lineage;
            }
        }

        return $matches;
    }

    public function withLineage(string $connection, TableState $lineage): self
    {
        $partitions = $this->partitions;
        $partitions[$connection][$lineage->lineageId] = $lineage;

        return new self($partitions, max($this->nextLineageId, $lineage->lineageId + 1));
    }

    public function nextLineageId(): int
    {
        return $this->nextLineageId;
    }
}
