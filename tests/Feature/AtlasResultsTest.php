<?php

declare(strict_types=1);

use MigrationAtlas\Atlas;
use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Discovery\DiscoveryOptions;
use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Reconstruction\State\Existence;
use MigrationAtlas\Reconstruction\State\TableStatus;
use MigrationAtlas\Support\ResultStatus;
use MigrationAtlas\Tests\Support\DiscoveryWorkspace;
use MigrationAtlas\Tests\Support\MigrationSource;

beforeEach(function () {
    $this->workspace = new DiscoveryWorkspace;
    $this->discovery = new DiscoveryOptions(['migrations'], $this->workspace->root);
});

afterEach(function () {
    $this->workspace->cleanup();
});

it('assembles the inspect result for a created table', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) {
            $t->id();
            $t->string("name", 40)->nullable();
        });'));

    $result = $this->app->make(Atlas::class)->inspect('users', $this->discovery);

    expect($result->meta->command)->toBe('schema:inspect')
        ->and($result->meta->status)->toBe(TableStatus::Complete)
        ->and($result->meta->globalUncertainty)->toBeFalse()
        ->and($result->meta->migrationsScanned)->toBe(1)
        ->and($result->meta->migrationsAnalyzed)->toBe(1)
        ->and($result->meta->operationsApplied)->toBe(3)
        ->and($result->data->status)->toBe(ResultStatus::Complete)
        ->and($result->data->existence)->toBe(Existence::Present)
        ->and($result->data->name)->toBe('users')
        ->and($result->data->nameCertain)->toBeTrue()
        ->and($result->data->reasons)->toBe([])
        ->and($result->data->fidelity)->toBe(Fidelity::Full)
        ->and($result->data->createdBy)->not->toBeNull()
        ->and($result->data->droppedBy)->toBeNull()
        ->and(array_keys($result->data->columns))->toBe(['id', 'name'])
        ->and($result->data->columns['name']->length)->toBe(40)
        ->and(count($result->data->historySummary))->toBe(3)
        ->and($result->diagnostics->items)->toBe([]);
});

it('assembles the dropped-table inspect payload with last-known state', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); $t->string("name"); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_drop_users.php', MigrationSource::up('
        Schema::drop("users");'));

    $result = $this->app->make(Atlas::class)->inspect('users', $this->discovery);

    expect($result->data->status)->toBe(ResultStatus::Dropped)
        ->and($result->data->existence)->toBe(Existence::Absent)
        ->and($result->data->droppedBy)->not->toBeNull()
        ->and(array_keys($result->data->columns))->toBe(['id', 'name'])
        ->and($result->meta->status)->toBe(TableStatus::Complete);
});

it('assembles caveated and clean not-found error envelopes', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_raw.php', MigrationSource::up('
        \Illuminate\Support\Facades\DB::statement("DROP TABLE arbitrary");'));

    $clean = $this->app->make(Atlas::class)->inspect('users', $this->discovery);
    $caveated = $this->app->make(Atlas::class)->inspect('ghosts', $this->discovery);
    $caveat = $caveated->diagnostics->items[count($caveated->diagnostics->items) - 1];

    expect($clean->data)->not->toBeNull()
        ->and($caveated->data)->toBeNull()
        ->and($caveated->meta->status)->toBe(TableStatus::Incomplete)
        ->and($caveated->meta->globalUncertainty)->toBeTrue()
        ->and($caveated->meta->unattributableOperations)->toBe(1)
        ->and($caveated->meta->uncertaintySources)->toHaveCount(1)
        ->and($caveat->code)->toBe(DiagnosticCode::TableNotFound)
        ->and($caveat->message)->toContain('not proof of absence');
});

it('reports ambiguous identities with candidates and no silent winner', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_maybe_drop.php', MigrationSource::up('
        Schema::dropIfExists("users");', conditional: true));
    $this->workspace->migration('migrations/2026_01_03_000000_recreate_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->string("name"); });'));

    $result = $this->app->make(Atlas::class)->inspect('users', $this->discovery);
    $error = $result->diagnostics->items[count($result->diagnostics->items) - 1];

    expect($result->data)->toBeNull()
        ->and($result->meta->status)->toBe(TableStatus::Incomplete)
        ->and($error->code)->toBe(DiagnosticCode::AmbiguousTableIdentity)
        ->and($error->message)->toContain('lineage 1')
        ->and($error->message)->toContain('lineage 2')
        ->and($error->message)->toContain('None is silently chosen');
});

it('keeps the skipped-file cap through result assembly', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_broken.php', '<?php syntax error');

    $result = $this->app->make(Atlas::class)->inspect('users', $this->discovery);

    expect($result->meta->migrationsSkipped)->toBe(1)
        ->and($result->meta->status)->toBe(TableStatus::Incomplete)
        ->and($result->meta->skippedMigrations)->toBe(1)
        ->and($result->data->status)->toBe(ResultStatus::Incomplete)
        ->and($result->data->existence)->toBe(Existence::Unknown)
        ->and($result->data->nameCertain)->toBeFalse();
});

it('assembles history timelines with conditional events flagged', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_maybe_email.php', MigrationSource::up('
        Schema::table("users", function ($t) { $t->string("email"); });', conditional: true));

    $result = $this->app->make(Atlas::class)->history('users', $this->discovery);
    $events = $result->data->lineages[0]->events;

    expect($result->data->currentName)->toBe('users')
        ->and($result->data->existence)->toBe(Existence::Present)
        ->and($result->meta->status)->toBe(TableStatus::Incomplete)
        ->and(count($events))->toBe(4)
        ->and($events[3]->conditional)->toBeTrue()
        ->and($events[3]->operation)->toBe('add_column')
        ->and($events[3]->summary)->toContain('email')
        ->and($events[0]->date)->toBe('2026-01-01 00:00:00')
        ->and($events[3]->date)->toBe('2026-01-02 00:00:00')
        ->and($events[0]->file)->toBe('2026_01_01_000000_create_users');
});

it('renders drop and recreate history as separated lineages with the live one last', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->string("legacy"); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_drop_users.php', MigrationSource::up('
        Schema::drop("users");'));
    $this->workspace->migration('migrations/2026_01_03_000000_recreate_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));

    $result = $this->app->make(Atlas::class)->history('users', $this->discovery);
    $lineages = $result->data->lineages;

    expect(count($lineages))->toBe(2)
        ->and($lineages[0]->existence)->toBe(Existence::Absent)
        ->and($lineages[0]->droppedBy)->not->toBeNull()
        ->and(count($lineages[0]->events))->toBe(3)
        ->and($lineages[1]->existence)->toBe(Existence::Present)
        ->and(count($lineages[1]->events))->toBe(2)
        ->and($result->data->currentName)->toBe('users')
        ->and($result->data->existence)->toBe(Existence::Present);
});

it('resolves history by the former name of a renamed table', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_and_rename.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });
        Schema::rename("users", "members");'));

    $result = $this->app->make(Atlas::class)->history('users', $this->discovery);

    expect($result->data->currentName)->toBe('members')
        ->and($result->data->formerNames)->toBe(['users'])
        ->and($result->data->lineages)->toHaveCount(1)
        ->and($result->data->lineages[0]->name)->toBe('members');
});

it('traces a rename chain in both directions with provenance', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->string("user_email", 40); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_rename_column.php', MigrationSource::up('
        Schema::table("users", function ($t) { $t->renameColumn("user_email", "email"); });'));
    $this->workspace->migration('migrations/2026_01_03_000000_change_email.php', MigrationSource::up('
        Schema::table("users", function ($t) { $t->string("email", 90)->nullable()->change(); });'));

    $former = $this->app->make(Atlas::class)->trace('users', 'user_email', $this->discovery);
    $current = $this->app->make(Atlas::class)->trace('users', 'email', $this->discovery);

    expect($former->data->requestedColumn)->toBe('user_email')
        ->and($former->data->currentColumn)->toBe('email')
        ->and($former->data->currentTable)->toBe('users')
        ->and($former->data->exists)->toBeTrue()
        ->and($former->data->definition->length)->toBe(90)
        ->and($former->data->definition->formerNames)->toBe(['user_email'])
        ->and(count($former->data->events))->toBe(3)
        ->and($former->data->events[0]->operation)->toBe('add_column')
        ->and($former->data->events[1]->operation)->toBe('rename_column')
        ->and($former->data->events[2]->operation)->toBe('change_column')
        ->and($current->data->currentColumn)->toBe('email')
        ->and(count($current->data->events))->toBe(3)
        ->and($current->data->events[0]->summary)->toContain('user_email');
});

it('terminates traces at certain drops with dropped-by provenance', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->string("email"); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_drop_email.php', MigrationSource::up('
        Schema::table("users", function ($t) { $t->dropColumn("email"); });'));

    $result = $this->app->make(Atlas::class)->trace('users', 'email', $this->discovery);

    expect($result->data->exists)->toBeFalse()
        ->and($result->data->existence)->toBe(Existence::Absent)
        ->and($result->data->droppedBy)->not->toBeNull()
        ->and($result->data->definition->name)->toBe('email')
        ->and(count($result->data->events))->toBe(2)
        ->and($result->data->events[1]->operation)->toBe('drop_column');
});

it('traces a column of a dropped table as absent with its last-known definition', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->string("email"); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_drop_users.php', MigrationSource::up('
        Schema::drop("users");'));

    $result = $this->app->make(Atlas::class)->trace('users', 'email', $this->discovery);

    expect($result->data->exists)->toBeFalse()
        ->and($result->data->existence)->toBe(Existence::Absent)
        ->and($result->data->definition->name)->toBe('email')
        ->and(count($result->data->events))->toBe(1)
        ->and($result->data->events[0]->operation)->toBe('add_column');
});

it('keeps conditional columns uncertain in traces', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));
    $this->workspace->migration('migrations/2026_01_02_000000_maybe_phone.php', MigrationSource::up('
        Schema::table("users", function ($t) { $t->string("phone"); });', conditional: true));

    $result = $this->app->make(Atlas::class)->trace('users', 'phone', $this->discovery);

    expect($result->data->exists)->toBeNull()
        ->and($result->data->existence)->toBe(Existence::Unknown)
        ->and($result->data->definition->name)->toBe('phone')
        ->and($result->data->droppedBy)->toBeNull()
        ->and($result->data->events[0]->conditional)->toBeTrue()
        ->and($result->meta->status)->toBe(TableStatus::Incomplete);
});

it('reports unknown columns without claiming proven absence', function () {
    $this->workspace->migration('migrations/2026_01_01_000000_create_users.php', MigrationSource::up('
        Schema::create("users", function ($t) { $t->id(); });'));

    $result = $this->app->make(Atlas::class)->trace('users', 'ghost_col', $this->discovery);

    expect($result->data)->toBeNull()
        ->and($result->diagnostics->items[count($result->diagnostics->items) - 1]->code)->toBe(DiagnosticCode::ColumnNotFound);
});

it('reports no migrations found as an error envelope', function () {
    $this->workspace->directory('migrations');

    $result = $this->app->make(Atlas::class)->inspect('users', $this->discovery);

    expect($result->data)->toBeNull()
        ->and($result->meta->migrationsScanned)->toBe(0)
        ->and($result->meta->status)->toBe(TableStatus::Complete)
        ->and($result->diagnostics->items)->toHaveCount(1)
        ->and($result->diagnostics->items[0]->code)->toBe(DiagnosticCode::NoMigrationsFound);
});

it('resolves the atlas through the container with every database connection blocked', function () {
    expect($this->app->make(Atlas::class))->toBeInstanceOf(Atlas::class)
        ->and($this->app->make(Atlas::class))->not->toBe($this->app->make(Atlas::class));
});
