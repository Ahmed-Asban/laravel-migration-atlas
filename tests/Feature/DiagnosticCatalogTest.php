<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Tests\Support\DiscoveryWorkspace;
use MigrationAtlas\Tests\Support\MigrationSource;

beforeEach(function () {
    $this->workspace = new DiscoveryWorkspace;
    $this->migrations = $this->workspace->directory('migrations');
});

afterEach(function () {
    $this->workspace->cleanup();
});

/**
 * The approved diagnostics catalog from the specification. Every code is
 * triggered by a dedicated fixture somewhere in the suite; this test keeps
 * the enum and the catalog in lockstep and drives every CLI-reachable
 * code end to end.
 */
it('keeps the diagnostic enum exactly aligned with the approved catalog', function () {
    $catalog = [
        'NON_STANDARD_FILENAME', 'MIGRATION_SYNTAX_ERROR', 'MIGRATION_PARSE_FAILURE',
        'FILE_TOO_LARGE', 'MIGRATION_SOURCE_UNREADABLE', 'MIGRATION_CLASS_NOT_FOUND',
        'UP_METHOD_MISSING', 'SHOULD_RUN_DETECTED', 'UNRESOLVED_DYNAMIC_EXPRESSION',
        'UNATTRIBUTABLE_OPERATION', 'UNRESOLVED_CONDITIONAL_REGION', 'UNRESOLVED_UNKNOWN_METHOD',
        'RAW_SQL_DETECTED', 'SCHEMA_DUMP_DETECTED', 'DUPLICATE_TABLE_CREATE', 'DUPLICATE_COLUMN',
        'ALTER_UNKNOWN_TABLE', 'DROP_UNKNOWN_TABLE', 'DROP_UNKNOWN_COLUMN',
        'RENAME_UNKNOWN_TABLE', 'RENAME_UNKNOWN_COLUMN', 'RENAME_TARGET_CONFLICT',
        'NO_MIGRATIONS_FOUND', 'TABLE_NOT_FOUND', 'COLUMN_NOT_FOUND', 'AMBIGUOUS_TABLE_IDENTITY',
    ];

    $enum = array_map(static fn ($case) => $case->value, DiagnosticCode::cases());
    sort($catalog);
    sort($enum);

    expect($enum)->toBe($catalog)
        ->and(count($enum))->toBe(26);
});

it('triggers every cli-reachable catalog code through the real pipeline', function (string $code, array $sources, string $command, array $arguments) {
    if ($code === 'FILE_TOO_LARGE') {
        config()->set('migration-atlas.max_file_size', 100);
    }

    $dumpFile = null;

    if ($code === 'SCHEMA_DUMP_DETECTED') {
        // The CLI derives the project root from the application, so the
        // dump fixture lives under the testbench app root for this case.
        @mkdir(base_path('database/schema'), 0777, true);
        $dumpFile = base_path('database/schema/default-schema.sql');
        file_put_contents($dumpFile, '-- baseline');
    }

    foreach ($sources as $name => $source) {
        $this->workspace->migration($name, $source);
    }

    try {
        Artisan::call($command, $arguments + ['--path' => [$this->migrations], '--json' => true]);
        $envelope = json_decode(Artisan::output(), true);
        $codes = array_map(static fn ($diagnostic) => $diagnostic['code'], $envelope['diagnostics']);

        expect($codes)->toContain($code);
    } finally {
        if ($dumpFile !== null && is_file($dumpFile)) {
            @unlink($dumpFile);
        }
    }
})->with([
    'non-standard filename' => ['NON_STANDARD_FILENAME', ['migrations/legacy_migration.php' => MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });')], 'schema:inspect', ['table' => 'users']],
    'syntax error' => ['MIGRATION_SYNTAX_ERROR', ['migrations/2026_01_01_000000_broken.php' => '<?php syntax error'], 'schema:inspect', ['table' => 'users']],
    'oversized source' => ['FILE_TOO_LARGE', ['migrations/2026_01_01_000000_create_users.php' => MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });')], 'schema:inspect', ['table' => 'users']],
    'missing class' => ['MIGRATION_CLASS_NOT_FOUND', ['migrations/2026_01_01_000000_bare.php' => '<?php throw new RuntimeException("never");'], 'schema:inspect', ['table' => 'users']],
    'missing up method' => ['UP_METHOD_MISSING', ['migrations/2026_01_01_000000_down_only.php' => '<?php
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function down(): void {}
};'], 'schema:inspect', ['table' => 'users']],
    'conditional migration' => ['SHOULD_RUN_DETECTED', ['migrations/2026_01_01_000000_create_users.php' => MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'), 'migrations/2026_01_02_000000_maybe.php' => MigrationSource::up('
        Schema::table("users", function ($t) { $t->string("note"); });', conditional: true)], 'schema:inspect', ['table' => 'users']],
    'dynamic expression' => ['UNRESOLVED_DYNAMIC_EXPRESSION', ['migrations/2026_01_01_000000_create_users.php' => MigrationSource::up('
        Schema::create("users", function ($t) { $t->string($name); });')], 'schema:inspect', ['table' => 'users']],
    'unattributable operation' => ['UNATTRIBUTABLE_OPERATION', ['migrations/2026_01_01_000000_dynamic.php' => MigrationSource::up('
        Schema::table($name, function ($t) { $t->id(); });')], 'schema:inspect', ['table' => 'users']],
    'conditional region' => ['UNRESOLVED_CONDITIONAL_REGION', ['migrations/2026_01_01_000000_region.php' => MigrationSource::up('
        if ($enabled) { Schema::create("users", function ($t) { $t->id(); }); }')], 'schema:inspect', ['table' => 'users']],
    'unknown method' => ['UNRESOLVED_UNKNOWN_METHOD', ['migrations/2026_01_01_000000_create_users.php' => MigrationSource::up('
        Schema::create("users", function ($t) { $t->string("x")->storedAs("computed"); });')], 'schema:inspect', ['table' => 'users']],
    'raw sql' => ['RAW_SQL_DETECTED', ['migrations/2026_01_01_000000_raw.php' => MigrationSource::up('
        \Illuminate\Support\Facades\DB::statement("DROP TABLE arbitrary");')], 'schema:inspect', ['table' => 'users']],
    'schema dump' => ['SCHEMA_DUMP_DETECTED', ['migrations/2026_01_01_000000_create_users.php' => MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });')], 'schema:inspect', ['table' => 'users']],
    'duplicate create' => ['DUPLICATE_TABLE_CREATE', ['migrations/2026_01_01_000000_create_users.php' => MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'), 'migrations/2026_01_02_000000_recreate.php' => MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });')], 'schema:inspect', ['table' => 'users']],
    'duplicate column' => ['DUPLICATE_COLUMN', ['migrations/2026_01_01_000000_create_users.php' => MigrationSource::up('
        Schema::create("users", function ($t) { $t->string("name"); });'), 'migrations/2026_01_02_000000_again.php' => MigrationSource::up('
        Schema::table("users", function ($t) { $t->string("name"); });')], 'schema:inspect', ['table' => 'users']],
    'alter unknown table' => ['ALTER_UNKNOWN_TABLE', ['migrations/2026_01_01_000000_alter.php' => MigrationSource::up('
        Schema::table("legacy", function ($t) { $t->string("note"); });')], 'schema:inspect', ['table' => 'legacy']],
    'drop unknown table' => ['DROP_UNKNOWN_TABLE', ['migrations/2026_01_01_000000_drop.php' => MigrationSource::up('
        Schema::drop("ghosts");')], 'schema:inspect', ['table' => 'users']],
    'drop unknown column' => ['DROP_UNKNOWN_COLUMN', ['migrations/2026_01_01_000000_create_users.php' => MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'), 'migrations/2026_01_02_000000_drop_ghost.php' => MigrationSource::up('
        Schema::table("users", function ($t) { $t->dropColumn("ghost"); });')], 'schema:inspect', ['table' => 'users']],
    'rename unknown table' => ['RENAME_UNKNOWN_TABLE', ['migrations/2026_01_01_000000_rename.php' => MigrationSource::up('
        Schema::rename("ghosts", "spirits");')], 'schema:inspect', ['table' => 'users']],
    'rename unknown column' => ['RENAME_UNKNOWN_COLUMN', ['migrations/2026_01_01_000000_create_users.php' => MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'), 'migrations/2026_01_02_000000_rename_ghost.php' => MigrationSource::up('
        Schema::table("users", function ($t) { $t->renameColumn("ghost", "spirit"); });')], 'schema:inspect', ['table' => 'users']],
    'rename target conflict' => ['RENAME_TARGET_CONFLICT', ['migrations/2026_01_01_000000_create.php' => MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'), 'migrations/2026_01_02_000000_create_members.php' => MigrationSource::up('
        Schema::create("members", function ($t) { $t->id(); });'), 'migrations/2026_01_03_000000_conflict.php' => MigrationSource::up('
        Schema::rename("users", "members");')], 'schema:inspect', ['table' => 'users']],
    'no migrations' => ['NO_MIGRATIONS_FOUND', [], 'schema:inspect', ['table' => 'users']],
    'table not found' => ['TABLE_NOT_FOUND', ['migrations/2026_01_01_000000_create_users.php' => MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });')], 'schema:inspect', ['table' => 'ghosts']],
    'column not found' => ['COLUMN_NOT_FOUND', ['migrations/2026_01_01_000000_create_users.php' => MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });')], 'schema:trace', ['column' => 'users.ghost']],
    'ambiguous identity' => ['AMBIGUOUS_TABLE_IDENTITY', ['migrations/2026_01_01_000000_create_users.php' => MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'), 'migrations/2026_01_02_000000_maybe.php' => MigrationSource::up('
        Schema::dropIfExists("users");', conditional: true), 'migrations/2026_01_03_000000_recreate.php' => MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });')], 'schema:inspect', ['table' => 'users']],
]);
