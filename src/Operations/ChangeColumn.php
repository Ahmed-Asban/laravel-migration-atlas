<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

final readonly class ChangeColumn extends TableOperation
{
    /**
     * The declared definition covers only the type and modifiers stated in
     * this migration (Laravel 11+ semantics); its fidelity is reduced because
     * undeclared runtime state cannot be reconstructed statically.
     */
    public function __construct(
        SourceLocation $source,
        ExecutionCertainty $execution,
        string $table,
        string $connection,
        public ColumnDefinitionDto $column,
    ) {
        parent::__construct($source, $execution, $table, $connection, 'change_column');
    }

    public function describe(): string
    {
        return "Change column {$this->table}.{$this->column->name}";
    }
}
