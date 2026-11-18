<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use MigrationAtlas\Tests\Support\DiscoveryWorkspace;
use MigrationAtlas\Tests\Support\MigrationSource;

beforeEach(function () {
    $this->workspace = new DiscoveryWorkspace;
    $this->migrations = $this->workspace->directory('migrations');
});

afterEach(function () {
    $this->workspace->cleanup();
});

function command_output(string $command, array $arguments): array
{
    $exit = Artisan::call($command, $arguments);

    return [$exit, Artisan::output()];
}

function users_corpus(): void
{
    test()->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) {
            $t->id();
            $t->string("email", 90)->nullable();
            $t->uuid("token");
        });'));
    test()->workspace->migration('migrations/2026_01_02_000000_rename_column.php', MigrationSource::up('
        Schema::table("users", function ($t) { $t->renameColumn("email", "mail"); });'));
}

it('renders a complete inspect result as text and exits zero', function () {
    users_corpus();

    [$exit, $output] = command_output('schema:inspect', ['table' => 'users', '--path' => [$this->migrations]]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('users — expected schema (reconstructed from 2 migration files; no database was read)')
        ->and($output)->toContain('Status: complete')
        ->and($output)->toContain('COLUMNS')
        ->and($output)->toContain('bigint unsigned, not null, auto-increment, primary')
        ->and($output)->toContain('added by 2026_01_01_000000_create_users.php:9')
        ->and($output)->toContain('renamed from email by 2026_01_02_000000_rename_column.php:8')
        ->and($output)->toContain('† reduced fidelity: type captured generically (PARTIALLY SUPPORTED construct)')
        ->and($output)->toContain('HISTORY SUMMARY')
        ->and($output)->toContain('6 operations across 2 migrations');
});

it('emits the deterministic json envelope byte-identically across runs', function () {
    users_corpus();

    [$exit, $json] = command_output('schema:inspect', ['table' => 'users', '--path' => [$this->migrations], '--json' => true]);
    [$second, $again] = command_output('schema:inspect', ['table' => 'users', '--path' => [$this->migrations], '--json' => true]);
    $envelope = json_decode($json, true);

    expect($exit)->toBe(0)
        ->and($second)->toBe(0)
        ->and($json)->toBe($again)
        ->and($envelope['meta']['command'])->toBe('schema:inspect')
        ->and($envelope['meta']['table'])->toBe('users')
        ->and($envelope['meta']['connection'])->toBe('default')
        ->and($envelope['meta']['analysis']['status'])->toBe('complete')
        ->and($envelope['meta']['analysis']['global_uncertainty']['present'])->toBeFalse()
        ->and($envelope['meta']['analysis']['migrations_scanned'])->toBe(2)
        ->and($envelope['meta']['analysis']['migrations_analyzed'])->toBe(2)
        ->and($envelope['meta']['analysis']['operations_applied'])->toBe(6)
        ->and($envelope['data']['status'])->toBe('complete')
        ->and($envelope['data']['existence'])->toBe('present')
        ->and($envelope['data']['name_certain'])->toBeTrue()
        ->and($envelope['data']['fidelity'])->toBe('reduced')
        ->and($envelope['data']['created_by']['file'])->toBe('2026_01_01_000000_create_users.php')
        ->and($envelope['data']['columns'][0]['name'])->toBe('id')
        ->and($envelope['data']['columns'][0]['primary'])->toBeTrue()
        ->and($envelope['data']['columns'][1]['name'])->toBe('mail')
        ->and($envelope['data']['columns'][1]['possible_names'])->toBe(['email', 'mail'])
        ->and($envelope['data']['columns'][2]['type'])->toBe('uuid')
        ->and($envelope['data']['columns'][2]['fidelity'])->toBe('reduced')
        ->and($envelope['diagnostics'])->toBe([]);
});

it('renders dropped tables with last-known state and exits zero even under strict', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_drop_users.php', MigrationSource::up('
        Schema::drop("users");'));

    [$exit, $output] = command_output('schema:inspect', ['table' => 'users', '--path' => [$this->migrations], '--strict' => true]);
    [$jsonExit, $json] = command_output('schema:inspect', ['table' => 'users', '--path' => [$this->migrations], '--json' => true]);
    $envelope = json_decode($json, true);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Status: DROPPED — showing last known state before the drop')
        ->and($output)->toContain('dropped by 2026_01_02_000000_drop_users.php:8')
        ->and($jsonExit)->toBe(0)
        ->and($envelope['data']['status'])->toBe('dropped')
        ->and($envelope['data']['existence'])->toBe('absent')
        ->and($envelope['data']['dropped_by']['line'])->toBe(8);
});

it('exits two for incomplete results under strict and zero without it', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_maybe_drop.php', MigrationSource::up('
        Schema::dropIfExists("users");', conditional: true));

    [$plain] = command_output('schema:inspect', ['table' => 'users', '--path' => [$this->migrations]]);
    [$strict] = command_output('schema:inspect', ['table' => 'users', '--path' => [$this->migrations], '--strict' => true]);
    [, $output] = command_output('schema:inspect', ['table' => 'users', '--path' => [$this->migrations]]);

    expect($plain)->toBe(0)
        ->and($strict)->toBe(2)
        ->and($output)->toContain('Status: INCOMPLETE · existence: unknown')
        ->and($output)->toContain('conditional: 2026_01_02_000000_maybe_drop.php')
        ->and($output)->toContain('conditional migration — whether it executed is not statically determinable');
});

it('keeps errors at exit one even under strict', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));

    [$notFound] = command_output('schema:inspect', ['table' => 'ghosts', '--path' => [$this->migrations], '--strict' => true]);
    [$noColumn] = command_output('schema:trace', ['column' => 'users.ghost', '--path' => [$this->migrations], '--strict' => true]);
    [, $output] = command_output('schema:inspect', ['table' => 'ghosts', '--path' => [$this->migrations]]);

    expect($notFound)->toBe(1)
        ->and($noColumn)->toBe(1)
        ->and($output)->toContain("No analyzed migration defines 'ghosts'.");
});

it('caveats not-found results with the run uncertainty', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });
        \Illuminate\Support\Facades\DB::statement("DROP TABLE arbitrary");'));

    [$exit, $output] = command_output('schema:inspect', ['table' => 'ghosts', '--path' => [$this->migrations]]);
    [$jsonExit, $json] = command_output('schema:inspect', ['table' => 'ghosts', '--path' => [$this->migrations], '--json' => true]);
    $envelope = json_decode($json, true);

    expect($exit)->toBe(1)
        ->and($output)->toContain('not proof of absence')
        ->and($jsonExit)->toBe(1)
        ->and($envelope['data'])->toBeNull()
        ->and($envelope['meta']['analysis']['status'])->toBe('incomplete')
        ->and($envelope['meta']['analysis']['global_uncertainty']['unattributable_operations'])->toBe(1)
        ->and($envelope['meta']['analysis']['global_uncertainty']['sources'][0]['reason'])->toBe('UNATTRIBUTABLE_OPERATION');
});

it('keeps the skipped-file cap visible through the CLI', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_broken.php', '<?php syntax error');

    [$strict] = command_output('schema:inspect', ['table' => 'users', '--path' => [$this->migrations], '--strict' => true]);
    [, $json] = command_output('schema:inspect', ['table' => 'users', '--path' => [$this->migrations], '--json' => true]);
    $envelope = json_decode($json, true);

    expect($strict)->toBe(2)
        ->and($envelope['meta']['analysis']['migrations_skipped'])->toBe(1)
        ->and($envelope['data']['status'])->toBe('incomplete')
        ->and($envelope['data']['existence'])->toBe('unknown')
        ->and($envelope['data']['name_certain'])->toBeFalse()
        ->and($envelope['meta']['analysis']['global_uncertainty']['skipped_migrations'])->toBe(1);
});

it('hints at other partitions when the requested partition lacks the table', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));

    [$exit, $output] = command_output('schema:inspect', ['table' => 'users', '--path' => [$this->migrations], '--connection' => 'archive']);

    expect($exit)->toBe(1)
        ->and($output)->toContain("No analyzed migration defines 'users'.")
        ->and($output)->toContain('This name matches identities in other partition(s): default.');
});

it('reports ambiguous identities with every candidate', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_maybe_drop.php', MigrationSource::up('
        Schema::dropIfExists("users");', conditional: true));
    $this->workspace->migration('migrations/2026_01_03_000000_recreate_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->string("name"); });'));

    [$exit, $output] = command_output('schema:inspect', ['table' => 'users', '--path' => [$this->migrations], '--strict' => true]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('Multiple live identities match \'users\'')
        ->and($output)->toContain('None is silently chosen')
        ->and($output)->toContain('(lineage 1)')
        ->and($output)->toContain('(lineage 2)');
});

it('renders conditional history events with the conditional marker', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_maybe_email.php', MigrationSource::up('
        Schema::table("users", function ($t) { $t->string("email"); });', conditional: true));

    [$exit, $output] = command_output('schema:history', ['table' => 'users', '--path' => [$this->migrations]]);
    [$strict] = command_output('schema:history', ['table' => 'users', '--path' => [$this->migrations], '--strict' => true]);

    expect($exit)->toBe(0)
        ->and($strict)->toBe(2)
        ->and($output)->toContain('users — migration history (2 migrations touch this identity)')
        ->and($output)->toContain('[conditional ‡]')
        ->and($output)->toContain('‡ migration defines shouldRun() — whether it executed is not statically determinable')
        ->and($output)->toContain('Status: INCOMPLETE · existence: present · 2 migration files scanned');
});

it('traces rename chains in both directions through the CLI', function () {
    users_corpus();

    [$exit, $output] = command_output('schema:trace', ['column' => 'users.email', '--path' => [$this->migrations]]);
    [$former, $json] = command_output('schema:trace', ['column' => 'users.email', '--path' => [$this->migrations], '--json' => true]);
    $envelope = json_decode($json, true);

    expect($exit)->toBe(0)
        ->and($output)->toContain('users.email — column trace (resolved to current name: users.mail)')
        ->and($output)->toContain('Add column users.email')
        ->and($output)->toContain('Rename column users.email to mail')
        ->and($output)->toContain('Current definition: string(90), nullable')
        ->and($output)->toContain('Status: exists · analysis complete')
        ->and($envelope['data']['current']['column'])->toBe('mail')
        ->and($envelope['data']['requested']['column'])->toBe('email')
        ->and($envelope['data']['current']['exists'])->toBeTrue();
});

it('terminates dropped column traces with dropped-by provenance', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->string("email"); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_drop_email.php', MigrationSource::up('
        Schema::table("users", function ($t) { $t->dropColumn("email"); });'));

    [$exit, $output] = command_output('schema:trace', ['column' => 'users.email', '--path' => [$this->migrations], '--strict' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Last known definition: string, not null')
        ->and($output)->toContain('Status: dropped (by 2026_01_02_000000_drop_email.php:8) · analysis complete');
});

it('runs the full pipeline without any database access', function () {
    users_corpus();

    // The test environment blocks every database connection; reaching the
    // renderer proves no PDO instance was ever constructed.
    [$exit] = command_output('schema:inspect', ['table' => 'users', '--path' => [$this->migrations]]);

    expect($exit)->toBe(0);
});
