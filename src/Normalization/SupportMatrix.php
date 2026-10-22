<?php

declare(strict_types=1);

namespace MigrationAtlas\Normalization;

final readonly class SupportMatrix
{
    /** @var array<string, SupportDefinition> */
    private array $definitions;

    public function __construct()
    {
        $entries = [];

        foreach ($this->groups() as [$category, $methods, $support, $tier, $normalizer, $implemented]) {
            foreach ($methods as $method) {
                $key = $category.'::'.($category === 'modifier' ? $method : strtolower($method));
                $entries[$key] = new SupportDefinition($category, $method, $support, $tier, $normalizer, $implemented);
            }
        }

        $this->definitions = $entries;
    }

    /** @return list<SupportDefinition> */
    public function entries(): array
    {
        return array_values($this->definitions);
    }

    public function find(string $category, ?string $method): ?SupportDefinition
    {
        // Fluent modifier names are attribute keys, unlike real PHP methods.
        $key = $category.'::'.($category === 'modifier' ? ($method ?? '') : strtolower($method ?? ''));

        return $this->definitions[$key] ?? null;
    }

    public function blueprint(?string $method): ?SupportDefinition
    {
        foreach (['column', 'lifecycle', 'index', 'foreign'] as $category) {
            $entry = $this->find($category, $method);

            if ($entry !== null) {
                return $entry;
            }
        }

        return null;
    }

    /** @return list<array{string, list<string>, SupportLevel, ?int, ?string, bool}> */
    private function groups(): array
    {
        return [
            ['schema', ['create'], SupportLevel::Supported, null, 'create_table', true],
            ['schema', ['table'], SupportLevel::Supported, null, 'alter_table', true],
            ['schema', ['drop', 'dropIfExists'], SupportLevel::Supported, null, 'drop_table', true],
            ['schema', ['rename'], SupportLevel::Supported, null, 'rename_table', true],
            ['schema', ['connection', 'getConnection'], SupportLevel::Supported, null, 'parser_connection', true],
            ['schema', ['createIfNotExists'], SupportLevel::Deferred, null, null, false],
            ['schema', ['hasTable'], SupportLevel::Supported, null, 'guard_clause', false],
            ['column', ['id', 'string', 'integer', 'boolean', 'text', 'timestamps'], SupportLevel::Supported, 1, 'tier_one_column', true],
            ['column', ['bigIncrements', 'increments', 'integerIncrements', 'mediumIncrements', 'smallIncrements', 'tinyIncrements'], SupportLevel::Partial, 2, 'increment_column', false],
            ['column', ['bigInteger', 'unsignedInteger', 'unsignedBigInteger', 'date', 'dateTime', 'time', 'json', 'jsonb', 'decimal', 'float', 'double', 'enum', 'set', 'char', 'tinyText', 'mediumText', 'longText', 'uuid', 'ulid', 'year', 'foreignId', 'ipAddress', 'macAddress'], SupportLevel::Partial, 2, 'generic_column', false],
            ['column', ['foreignIdFor'], SupportLevel::Partial, 2, 'model_foreign_id', false],
            ['column', ['softDeletes', 'softDeletesTz', 'rememberToken', 'timestampsTz'], SupportLevel::Partial, 2, 'expanded_column', false],
            ['modifier', ['nullable', 'default'], SupportLevel::Supported, 1, 'static_modifier', true],
            ['modifier', ['useCurrent', 'useCurrentOnUpdate', 'unsigned', 'after', 'first', 'comment', 'charset', 'collation', 'change'], SupportLevel::Partial, 2, 'partial_modifier', false],
            ['modifier', ['storedAs', 'virtualAs', 'generatedAs'], SupportLevel::Deferred, null, null, false],
            ['lifecycle', ['dropColumn'], SupportLevel::Supported, null, 'drop_column', true],
            ['lifecycle', ['renameColumn'], SupportLevel::Supported, null, 'rename_column', true],
            ['index', ['primary', 'unique', 'index', 'fullText', 'spatialIndex', 'dropPrimary', 'dropUnique', 'dropIndex', 'dropFullText', 'dropSpatialIndex'], SupportLevel::Supported, null, 'index', false],
            ['foreign', ['foreign', 'dropForeign', 'foreignId->constrained'], SupportLevel::Supported, null, 'foreign_key', false],
            ['foreign_modifier', ['references', 'on', 'onDelete', 'onUpdate', 'constrained', 'cascadeOnDelete', 'restrictOnDelete', 'nullOnDelete', 'cascadeOnUpdate'], SupportLevel::Supported, null, 'foreign_key_chain', false],
            ['context', ['migration_connection', 'shouldRun'], SupportLevel::Supported, null, 'parser_context', true],
            ['context', ['down'], SupportLevel::NotAnalyzed, null, null, true],
            ['context', ['dynamic_default'], SupportLevel::Partial, 2, 'dynamic_default', false],
            ['context', ['dynamic_connection', 'dynamic_table', 'conditional_region', 'raw_sql', 'side_effect', 'skipped_migration'], SupportLevel::Deferred, null, 'unresolved_context', true],
            ['context', ['schema_dump'], SupportLevel::Deferred, null, null, false],
        ];
    }
}
