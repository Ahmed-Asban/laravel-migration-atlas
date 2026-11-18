<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use MigrationAtlas\Atlas;
use MigrationAtlas\Discovery\DiscoveryOptions;
use MigrationAtlas\Tests\Support\DiscoveryWorkspace;
use MigrationAtlas\Tests\Support\MigrationSource;

beforeEach(function () {
    $this->workspace = new DiscoveryWorkspace;
    $this->migrations = $this->workspace->directory('migrations');
});

afterEach(function () {
    $this->workspace->cleanup();
});

function run_cli(string $command, array $arguments): array
{
    $exit = Artisan::call($command, $arguments);

    return [$exit, Artisan::output()];
}

it('semantically ignores the exact idempotency guard shape', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_guarded_create.php', MigrationSource::up('
        if (Schema::hasTable("users")) { return; }
        Schema::create("users", function ($t) { $t->id(); });'));

    [$exit, $output] = run_cli('schema:inspect', ['table' => 'users', '--path' => [$this->migrations], '--json' => true]);
    $envelope = json_decode($output, true);

    expect($exit)->toBe(0)
        ->and($envelope['meta']['analysis']['status'])->toBe('complete')
        ->and($envelope['data']['status'])->toBe('complete')
        ->and($envelope['data']['existence'])->toBe('present')
        ->and($envelope['diagnostics'])->toBe([]);
});

it('recognizes fully qualified guard facades and empty-guard bodies', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_guarded.php', '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable("users")) {
            return;
        }
        Schema::create("users", function ($t) { $t->id(); });
    }
};');

    [$exit, $output] = run_cli('schema:inspect', ['table' => 'users', '--path' => [$this->migrations], '--json' => true]);
    $envelope = json_decode($output, true);

    expect($exit)->toBe(0)
        ->and($envelope['data']['status'])->toBe('complete')
        ->and($envelope['diagnostics'])->toBe([]);
});

it('keeps every guard deviation conservatively unresolved', function (string $guard) {
    $this->workspace->migration('migrations/2026_01_01_000000_guarded.php', MigrationSource::up('
        '.$guard.'
        Schema::create("users", function ($t) { $t->id(); });'));

    $envelope = json_decode(run_cli('schema:inspect', ['table' => 'users', '--path' => [$this->migrations], '--json' => true])[1], true);
    $codes = array_map(static fn ($d) => $d['code'], $envelope['diagnostics']);

    expect($codes)->toContain('UNRESOLVED_CONDITIONAL_REGION')
        ->and($envelope['data'])->toBeNull()
        ->and(end($envelope['diagnostics'])['code'])->toBe('TABLE_NOT_FOUND')
        ->and($envelope['meta']['analysis']['status'])->toBe('incomplete');
})->with([
    'dynamic table' => 'if (Schema::hasTable($table)) { return; }',
    'negated shape' => 'if (! Schema::hasTable("users")) { return; }',
    'extra statements' => 'if (Schema::hasTable("users")) { Schema::drop("other"); return; }',
    'else branch' => 'if (Schema::hasTable("users")) { return; } else { Schema::drop("other"); }',
    'valued return' => 'if (Schema::hasTable("users")) { return false; }',
]);

it('keeps an empty guard body unresolved without blocking the following create', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_empty_guard.php', MigrationSource::up('
        if (Schema::hasTable("users")) { }
        Schema::create("users", function ($t) { $t->id(); });'));

    $envelope = json_decode(run_cli('schema:inspect', ['table' => 'users', '--path' => [$this->migrations], '--json' => true])[1], true);
    $codes = array_map(static fn ($d) => $d['code'], $envelope['diagnostics']);

    expect($codes)->toContain('UNRESOLVED_CONDITIONAL_REGION')
        ->and($envelope['data']['status'])->toBe('complete')
        ->and($envelope['data']['existence'])->toBe('present');
});

it('detects schema dumps and caveats not-found with the dump reference', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_alter_legacy.php', MigrationSource::up('
        Schema::table("legacy", function ($t) { $t->string("note"); });'));
    $this->workspace->migration('database/schema/default-schema.sql', '-- squashed baseline (never parsed)');

    $options = new DiscoveryOptions(['migrations'], $this->workspace->root);
    $atlas = $this->app->make(Atlas::class);

    $ghosts = $atlas->inspect('users', $options);
    $legacy = $atlas->inspect('legacy', $options);
    $ghostCodes = array_map(static fn ($d) => $d->code->value, $ghosts->diagnostics->items);
    $legacyCodes = array_map(static fn ($d) => $d->code->value, $legacy->diagnostics->items);

    expect($ghosts->data)->toBeNull()
        ->and($ghostCodes)->toContain('SCHEMA_DUMP_DETECTED')
        ->and($ghostCodes)->toContain('TABLE_NOT_FOUND')
        ->and($ghosts->diagnostics->items[count($ghosts->diagnostics->items) - 1]->message)
        ->toContain('unanalyzed schema dump (database/schema/default-schema.sql)')
        ->and($ghosts->meta->status->value)->toBe('incomplete')
        ->and($legacy->data->existence->value)->toBe('present')
        ->and($legacy->data->status->value)->toBe('incomplete')
        ->and($legacyCodes)->toContain('ALTER_UNKNOWN_TABLE')
        ->and($legacyCodes)->toContain('SCHEMA_DUMP_DETECTED');
});

it('does not caveat connections without a dump', function () {
    $this->workspace->migration('database/schema/archive-schema.sql', '-- squashed baseline');
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));

    $options = new DiscoveryOptions(['migrations'], $this->workspace->root);
    $atlas = $this->app->make(Atlas::class);
    $result = $atlas->inspect('ghosts', $options, 'default');

    expect($result->meta->status->value)->toBe('complete')
        ->and($result->diagnostics->items)->toHaveCount(1)
        ->and($result->diagnostics->items[0]->message)->toBe("No analyzed migration defines 'ghosts'.");
});

it('caps live windows and keeps post-window tables complete (AC-6)', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_dynamic_alter.php', MigrationSource::up('
        Schema::table($name, function ($t) { $t->id(); });'));
    $this->workspace->migration('migrations/2026_01_03_000000_create_orders.php', MigrationSource::up('
        Schema::create("orders", function ($t) { $t->id(); });'));

    [$users, $usersOutput] = run_cli('schema:inspect', ['table' => 'users', '--path' => [$this->migrations], '--strict' => true]);
    [$usersPlain] = run_cli('schema:inspect', ['table' => 'users', '--path' => [$this->migrations]]);
    [, $ordersOutput] = run_cli('schema:inspect', ['table' => 'orders', '--path' => [$this->migrations], '--json' => true]);
    $orders = json_decode($ordersOutput, true);
    $usersEnvelope = json_decode(run_cli('schema:inspect', ['table' => 'users', '--path' => [$this->migrations], '--json' => true])[1], true);

    expect($users)->toBe(2)
        ->and($usersPlain)->toBe(0)
        ->and($usersOutput)->toContain('Status: INCOMPLETE · existence: present')
        ->and($usersOutput)->toContain('global: 2026_01_02_000000_dynamic_alter.php')
        ->and($usersEnvelope['data']['status'])->toBe('incomplete')
        ->and($usersEnvelope['data']['name_certain'])->toBeFalse()
        ->and($orders['data']['status'])->toBe('complete')
        ->and($orders['data']['existence'])->toBe('present')
        ->and($orders['data']['incompleteness_reasons'])->toBe([]);
});

it('caveats not-found with the raw-SQL ledger (AC-7)', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_legacy_sql.php', MigrationSource::up('
        \Illuminate\Support\Facades\DB::statement("DROP TABLE arbitrary");'));

    [, $output] = run_cli('schema:inspect', ['table' => 'users', '--path' => [$this->migrations]]);
    $envelope = json_decode(run_cli('schema:inspect', ['table' => 'users', '--path' => [$this->migrations], '--json' => true])[1], true);
    $codes = array_map(static fn ($d) => $d['code'], $envelope['diagnostics']);

    expect($output)->toContain('RAW_SQL_DETECTED')
        ->and($output)->toContain('not proof of absence')
        ->and($codes)->toContain('RAW_SQL_DETECTED')
        ->and($codes)->toContain('UNATTRIBUTABLE_OPERATION')
        ->and($codes)->toContain('TABLE_NOT_FOUND')
        ->and($envelope['meta']['analysis']['status'])->toBe('incomplete')
        ->and($envelope['meta']['analysis']['global_uncertainty']['sources'][0]['kind'])->toBe('operation');
});

it('reports caveated table-not-found when every file was skipped (AC-23)', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_broken.php', '<?php syntax error');
    $this->workspace->migration('migrations/2026_01_02_000000_broken_too.php', '<?php also broken');

    [$exit, $output] = run_cli('schema:inspect', ['table' => 'ghosts', '--path' => [$this->migrations], '--strict' => true]);
    $envelope = json_decode(run_cli('schema:inspect', ['table' => 'ghosts', '--path' => [$this->migrations], '--json' => true])[1], true);
    $codes = array_map(static fn ($d) => $d['code'], $envelope['diagnostics']);

    expect($exit)->toBe(1)
        ->and($codes)->toContain('MIGRATION_SYNTAX_ERROR')
        ->and($codes)->toContain('TABLE_NOT_FOUND')
        ->and($codes)->not->toContain('NO_MIGRATIONS_FOUND')
        ->and($envelope['meta']['analysis']['migrations_scanned'])->toBe(2)
        ->and($envelope['meta']['analysis']['migrations_analyzed'])->toBe(0)
        ->and($envelope['meta']['analysis']['migrations_skipped'])->toBe(2)
        ->and($envelope['meta']['analysis']['status'])->toBe('incomplete')
        ->and(end($envelope['diagnostics'])['message'])->toContain('not proof of absence');
});

it('keeps conditional destructive identities with strict exits (AC-25)', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_maybe_drop.php', MigrationSource::up('
        Schema::dropIfExists("users");', conditional: true));

    [, $output] = run_cli('schema:history', ['table' => 'users', '--path' => [$this->migrations], '--strict' => true]);

    expect($output)->toContain('Status: INCOMPLETE · existence: unknown')
        ->and($output)->toContain('conditional: 2026_01_02_000000_maybe_drop.php')
        ->and($output)->not->toContain('Status: DROPPED')
        ->and(run_cli('schema:history', ['table' => 'users', '--path' => [$this->migrations], '--strict' => true])[0])->toBe(2);
});
