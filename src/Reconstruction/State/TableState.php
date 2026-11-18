<?php

declare(strict_types=1);

namespace MigrationAtlas\Reconstruction\State;

use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Operations\SourceLocation;

/**
 * The replayed state of one table lineage within a connection partition.
 * Status, existence, name certainty, origin, and fidelity are independent
 * axes; reasons record why the lineage is incomplete. Once incomplete,
 * a lineage never silently returns to complete.
 */
final readonly class TableState
{
    /**
     * @param  list<string>  $formerNames
     * @param  list<string>  $possibleNames
     * @param  array<string, ColumnState>  $columns
     * @param  list<IndexState>  $indexes
     * @param  list<ForeignKeyState>  $foreignKeys
     * @param  list<IncompletenessReason>  $reasons
     */
    public function __construct(
        public int $lineageId,
        public string $name,
        public array $formerNames,
        public array $possibleNames,
        public bool $nameCertain,
        public TableStatus $status,
        public Existence $existence,
        public Origin $origin,
        public array $columns,
        public array $indexes,
        public array $foreignKeys,
        public array $reasons,
        public SourceLocation $source,
        public ?SourceLocation $droppedBy = null,
    ) {}

    /**
     * @param  list<string>  $formerNames
     * @param  list<string>  $possibleNames
     */
    public static function birth(
        int $lineageId,
        string $name,
        Existence $existence,
        TableStatus $status,
        Origin $origin,
        SourceLocation $source,
        array $formerNames = [],
        array $possibleNames = [],
    ): self {
        return new self($lineageId, $name, $formerNames, $possibleNames !== [] ? $possibleNames : [$name],
            true, $status, $existence, $origin, [], [], [], [], $source);
    }

    /**
     * The lineage's fidelity: reduced once any element is reduced.
     */
    public function fidelity(): Fidelity
    {
        foreach ([$this->columns, $this->indexes, $this->foreignKeys] as $elements) {
            foreach ($elements as $element) {
                if ($element->fidelity === Fidelity::Reduced) {
                    return Fidelity::Reduced;
                }
            }
        }

        return Fidelity::Full;
    }

    /**
     * @param  list<IncompletenessReason>  $reasons
     */
    public function withReasons(array $reasons): self
    {
        $merged = $this->reasons;

        foreach ($reasons as $reason) {
            $merged = IncompletenessReason::push($merged, $reason);
        }

        // A lineage carrying any reason is incomplete; reasons are sticky.
        return $this->with(status: TableStatus::Incomplete, reasons: $merged);
    }

    public function withColumn(ColumnState $column): self
    {
        $columns = $this->columns;
        $columns[$column->name] = $column;

        return $this->with(columns: $columns);
    }

    /**
     * Record another statically observed candidate current name; the
     * lineage's current name becomes uncertain.
     */
    public function withPossibleName(string $name): self
    {
        $possibleNames = in_array($name, $this->possibleNames, true) ? $this->possibleNames : [...$this->possibleNames, $name];

        return $this->with(possibleNames: $possibleNames, nameCertain: false);
    }

    public function withIndex(IndexState $index): self
    {
        return $this->with(indexes: [...$this->indexes, $index]);
    }

    public function withoutIndex(string $name): self
    {
        return $this->with(indexes: array_values(array_filter(
            $this->indexes,
            static fn (IndexState $index): bool => $index->name !== $name,
        )));
    }

    public function withForeignKey(ForeignKeyState $key): self
    {
        return $this->with(foreignKeys: [...$this->foreignKeys, $key]);
    }

    public function withoutForeignKey(string $name): self
    {
        return $this->with(foreignKeys: array_values(array_filter(
            $this->foreignKeys,
            static fn (ForeignKeyState $key): bool => $key->name !== $name,
        )));
    }

    /**
     * Immutable override; unspecified arguments keep their current values.
     *
     * @param  list<string>|null  $formerNames
     * @param  list<string>|null  $possibleNames
     * @param  array<string, ColumnState>|null  $columns
     * @param  list<IndexState>|null  $indexes
     * @param  list<ForeignKeyState>|null  $foreignKeys
     * @param  list<IncompletenessReason>|null  $reasons
     */
    public function with(
        ?string $name = null,
        ?array $formerNames = null,
        ?array $possibleNames = null,
        ?bool $nameCertain = null,
        ?TableStatus $status = null,
        ?Existence $existence = null,
        ?Origin $origin = null,
        ?array $columns = null,
        ?array $indexes = null,
        ?array $foreignKeys = null,
        ?array $reasons = null,
        ?SourceLocation $droppedBy = null,
    ): self {
        return new self(
            $this->lineageId,
            $name ?? $this->name,
            $formerNames ?? $this->formerNames,
            $possibleNames ?? $this->possibleNames,
            $nameCertain ?? $this->nameCertain,
            $status ?? $this->status,
            $existence ?? $this->existence,
            $origin ?? $this->origin,
            $columns ?? $this->columns,
            $indexes ?? $this->indexes,
            $foreignKeys ?? $this->foreignKeys,
            $reasons ?? $this->reasons,
            $this->source,
            $droppedBy ?? $this->droppedBy,
        );
    }
}
