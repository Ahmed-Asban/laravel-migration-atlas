<?php

declare(strict_types=1);

namespace MigrationAtlas\Operations;

use MigrationAtlas\Diagnostics\DiagnosticCode;

final readonly class UnresolvedOperation extends Operation
{
    public Attribution $attribution;

    /** @param list<string> $relatedTables Additional known rename endpoints. */
    public function __construct(
        SourceLocation $source,
        ExecutionCertainty $execution,
        public ?string $table,
        public ?string $connection,
        public DiagnosticCode $reason,
        public string $rawDescription,
        public bool $wholeFileUnknown = false,
        public array $relatedTables = [],
        public ?DiagnosticCode $cause = null,
    ) {
        parent::__construct($source, $execution, 'unresolved');
        $this->attribution = $table === null
            ? Attribution::Unattributable
            : ($connection === null ? Attribution::NameScoped : Attribution::Attributed);
    }

    public function describe(): string
    {
        return ($this->wholeFileUnknown ? 'Skipped migration: ' : 'Unresolved operation: ').$this->reason->value;
    }
}
