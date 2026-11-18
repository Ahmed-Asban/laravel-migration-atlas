<?php

declare(strict_types=1);

use MigrationAtlas\Diagnostics\DiagnosticCode;
use MigrationAtlas\Operations\Fidelity;
use MigrationAtlas\Reconstruction\State\Existence;
use MigrationAtlas\Reconstruction\State\IncompletenessOrigin;
use MigrationAtlas\Reconstruction\State\Origin;
use MigrationAtlas\Reconstruction\State\TableState;
use MigrationAtlas\Reconstruction\State\TableStatus;
use MigrationAtlas\Tests\Support\ReplayFixture;

it('reconstructs a created table with full column, index and key state', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) {
            $t->id();
            $t->string("name", 80)->nullable()->default("guest");
            $t->timestamps();
            $t->unique("name");
            $t->foreignId("user_id")->constrained();
        });
    }
};',
    ]);

    $users = ReplayFixture::lineage($result, 'users');

    expect($users->status)->toBe(TableStatus::Complete)
        ->and($users->existence)->toBe(Existence::Present)
        ->and($users->origin)->toBe(Origin::CreatedHere)
        ->and($users->nameCertain)->toBeTrue()
        ->and($users->fidelity())->toBe(Fidelity::Reduced)
        ->and($users->reasons)->toBe([])
        ->and($users->droppedBy)->toBeNull()
        ->and(array_keys($users->columns))->toBe(['id', 'name', 'created_at', 'updated_at', 'user_id'])
        ->and($users->columns['id']->unsigned)->toBeTrue()
        ->and($users->columns['id']->autoIncrement)->toBeTrue()
        ->and($users->columns['id']->primary)->toBeTrue()
        ->and($users->columns['name']->length)->toBe(80)
        ->and($users->columns['name']->nullable)->toBeTrue()
        ->and($users->columns['name']->hasDefault)->toBeTrue()
        ->and($users->columns['name']->default)->toBe('guest')
        ->and($users->columns['created_at']->nullable)->toBeTrue()
        ->and($users->columns['user_id']->type)->toBe('foreignId')
        ->and($users->columns['user_id']->unsigned)->toBeTrue()
        ->and(count($users->indexes))->toBe(1)
        ->and($users->indexes[0]->name)->toBe('users_name_unique')
        ->and(count($users->foreignKeys))->toBe(1)
        ->and($users->foreignKeys[0]->name)->toBe('users_user_id_foreign')
        ->and($users->foreignKeys[0]->referencedTable)->toBe('users')
        ->and($result->migrationsScanned)->toBe(1)
        ->and($result->migrationsAnalyzed)->toBe(1)
        ->and($result->migrationsSkipped)->toBe(0)
        ->and($result->operationsApplied)->toBe(8)
        ->and(count($result->events))->toBe(8)
        ->and($result->events[0]->operation)->toBe('create_table')
        ->and($result->events[0]->date)->toBe('2026-01-01 00:00:00')
        ->and($result->events[0]->conditional)->toBeFalse()
        ->and($result->events[0]->file)->toBe('2026_01_01_000000_replay_01')
        ->and($result->events[0]->sequence)->toBe(1);
});

it('merges several migrations altering one table', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->id(); $t->string("name"); });
    }
};',
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::table("users", function ($t) { $t->string("email")->nullable(); $t->index("email"); });
    }
};',
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::table("users", function ($t) { $t->text("bio"); });
    }
};',
    ]);

    $users = ReplayFixture::lineage($result, 'users');

    expect($users->status)->toBe(TableStatus::Complete)
        ->and(array_keys($users->columns))->toBe(['id', 'name', 'email', 'bio'])
        ->and($users->columns['email']->nullable)->toBeTrue()
        ->and(count($users->indexes))->toBe(1)
        ->and($result->operationsApplied)->toBe(8)
        ->and($result->migrationsAnalyzed)->toBe(3);
});

it('keeps dropped columns as absent with provenance and their events', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->string("a"); $t->string("b"); });
        Schema::table("users", function ($t) { $t->dropColumn("a"); });
    }
};',
    ]);

    $users = ReplayFixture::lineage($result, 'users');

    expect($users->status)->toBe(TableStatus::Complete)
        ->and($users->columns['a']->existence)->toBe(Existence::Absent)
        ->and($users->columns['a']->droppedBy)->not->toBeNull()
        ->and($users->columns['a']->droppedBy->line)->toBe(8)
        ->and($users->columns['b']->existence)->toBe(Existence::Present)
        ->and(count($result->events))->toBe(5);
});

it('marks duplicate creates and columns incomplete without crashing', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->id(); });
        Schema::create("users", function ($t) { $t->string("name"); });
        Schema::table("users", function ($t) { $t->string("name", 40); });
    }
};',
    ]);

    $live = array_values(array_filter(
        $result->registry->partition('default'),
        static fn (TableState $lineage): bool => $lineage->name === 'users' && $lineage->existence === Existence::Present,
    ));

    expect(count($live))->toBe(2)
        ->and($live[0]->status)->toBe(TableStatus::Incomplete)
        ->and($live[1]->status)->toBe(TableStatus::Incomplete)
        ->and(ReplayFixture::hasReason($live[0], IncompletenessOrigin::Conflict, DiagnosticCode::DuplicateTableCreate))->toBeTrue()
        ->and(ReplayFixture::hasReason($live[1], IncompletenessOrigin::Conflict, DiagnosticCode::DuplicateTableCreate))->toBeTrue()
        ->and(ReplayFixture::hasDiagnostic($result, DiagnosticCode::DuplicateTableCreate))->toBeTrue()
        ->and(ReplayFixture::hasDiagnostic($result, DiagnosticCode::DuplicateColumn))->toBeTrue()
        ->and($live[1]->columns['name']->length)->toBe(40);
});

it('applies the live-window rule around unattributable operations only', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->id(); });
    }
};',
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::table($name, function ($t) { $t->id(); });
    }
};',
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("orders", function ($t) { $t->id(); });
    }
};',
    ]);

    $users = ReplayFixture::lineage($result, 'users');
    $orders = ReplayFixture::lineage($result, 'orders');

    expect($users->status)->toBe(TableStatus::Incomplete)
        ->and($users->existence)->toBe(Existence::Present)
        ->and($users->nameCertain)->toBeFalse()
        ->and(ReplayFixture::hasReason($users, IncompletenessOrigin::Global, DiagnosticCode::UnattributableOperation))->toBeTrue()
        ->and($orders->status)->toBe(TableStatus::Complete)
        ->and($orders->existence)->toBe(Existence::Present)
        ->and($orders->nameCertain)->toBeTrue()
        ->and($orders->reasons)->toBe([])
        ->and(count($result->ledger->entries()))->toBe(1)
        ->and($result->ledger->entries()[0]->kind)->toBe('operation')
        ->and($result->ledger->entries()[0]->position)->toBe([2, 1]);
});

it('demotes tombstones to uncertain candidates after unknown effects', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->id(); });
        Schema::drop("users");
    }
};',
        '<?php
use Illuminate\Support\Facades\DB;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        DB::statement("DROP TABLE arbitrary");
    }
};',
    ]);

    $users = ReplayFixture::lineage($result, 'users');

    expect($users->existence)->toBe(Existence::Unknown)
        ->and($users->nameCertain)->toBeFalse()
        ->and($users->status)->toBe(TableStatus::Incomplete)
        ->and(ReplayFixture::hasReason($users, IncompletenessOrigin::Global, DiagnosticCode::UnattributableOperation))->toBeTrue();
});

it('caps the entire run after a skipped file', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->id(); });
        Schema::drop("users");
    }
};',
        '<?php syntax error',
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("orders", function ($t) { $t->id(); });
    }
};',
    ]);

    $users = ReplayFixture::lineage($result, 'users');
    $orders = ReplayFixture::lineage($result, 'orders');
    $skips = $result->ledger->skippedMigrations();

    expect($result->migrationsScanned)->toBe(3)
        ->and($result->migrationsAnalyzed)->toBe(2)
        ->and($result->migrationsSkipped)->toBe(1)
        ->and($users->existence)->toBe(Existence::Unknown)
        ->and($users->status)->toBe(TableStatus::Incomplete)
        ->and(ReplayFixture::hasReason($users, IncompletenessOrigin::Global, DiagnosticCode::MigrationSyntaxError))->toBeTrue()
        ->and($orders->existence)->toBe(Existence::Unknown)
        ->and($orders->status)->toBe(TableStatus::Incomplete)
        ->and($orders->nameCertain)->toBeFalse()
        ->and(ReplayFixture::hasReason($orders, IncompletenessOrigin::Global, DiagnosticCode::MigrationSyntaxError))->toBeTrue()
        ->and(count($skips))->toBe(1)
        ->and($skips[0]->kind)->toBe('skipped_migration')
        ->and($skips[0]->position)->toBe([2, 0])
        ->and($skips[0]->reason)->toBe(DiagnosticCode::MigrationSyntaxError)
        ->and($result->operationsApplied)->toBe(5)
        ->and(count($result->events))->toBe(5);
});

it('treats a verified empty migration as an analyzed no-op without a cap', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->id(); });
    }
};',
        '<?php
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
    }
};',
    ]);

    $users = ReplayFixture::lineage($result, 'users');

    expect($result->migrationsAnalyzed)->toBe(2)
        ->and($result->migrationsSkipped)->toBe(0)
        ->and($result->ledger->entries())->toBe([])
        ->and($users->status)->toBe(TableStatus::Complete)
        ->and($users->existence)->toBe(Existence::Present);
});

it('never merges connection partitions and routes wrappers independently', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->id(); });
        Schema::connection("archive")->drop("users");
    }
};',
    ]);

    $default = ReplayFixture::lineage($result, 'users', 'default');

    expect($default->status)->toBe(TableStatus::Complete)
        ->and($default->existence)->toBe(Existence::Present)
        ->and(array_keys($result->registry->partitions()))->toBe(['default'])
        ->and($result->registry->partition('archive'))->toBe([]);
});

it('applies name-scoped uncertainty across partitions including rename endpoints', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    protected $connection = "tenant";
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->id(); });
        Schema::rename("users", "members");
    }
};',
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::connection($target)->rename("members", "people");
    }
};',
    ]);

    $members = ReplayFixture::lineage($result, 'members', 'tenant');

    expect($members->status)->toBe(TableStatus::Incomplete)
        ->and($members->existence)->toBe(Existence::Present)
        ->and(ReplayFixture::hasReason($members, IncompletenessOrigin::Direct, DiagnosticCode::UnattributableOperation))->toBeTrue()
        ->and(count($result->ledger->entries()))->toBe(1);
});

it('keeps conditional creates as unknown candidates', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function shouldRun(): bool
    {
        return true;
    }
    public function up(): void
    {
        Schema::create("audit", function ($t) { $t->id(); });
    }
};',
    ]);

    $audit = ReplayFixture::lineage($result, 'audit');

    expect($audit->existence)->toBe(Existence::Unknown)
        ->and($audit->status)->toBe(TableStatus::Incomplete)
        ->and(ReplayFixture::hasReason($audit, IncompletenessOrigin::Conditional, DiagnosticCode::ShouldRunDetected))->toBeTrue()
        ->and(count($result->events))->toBe(2)
        ->and($result->events[1]->conditional)->toBeTrue();
});

it('keeps conditional drops as possible drops without tombstones', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->id(); });
    }
};',
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function shouldRun(): bool
    {
        return true;
    }
    public function up(): void
    {
        Schema::dropIfExists("users");
    }
};',
    ]);

    $users = ReplayFixture::lineage($result, 'users');

    expect($users->existence)->toBe(Existence::Unknown)
        ->and($users->droppedBy)->toBeNull()
        ->and($users->status)->toBe(TableStatus::Incomplete)
        ->and(ReplayFixture::hasReason($users, IncompletenessOrigin::Conditional, DiagnosticCode::ShouldRunDetected))->toBeTrue();
});

it('keeps conditional renames with both possible names and no move', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->id(); });
    }
};',
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function shouldRun(): bool
    {
        return true;
    }
    public function up(): void
    {
        Schema::rename("users", "members");
    }
};',
    ]);

    $users = ReplayFixture::lineage($result, 'users');

    expect($users->name)->toBe('users')
        ->and($users->possibleNames)->toBe(['users', 'members'])
        ->and($users->nameCertain)->toBeFalse()
        ->and($users->existence)->toBe(Existence::Present)
        ->and($users->status)->toBe(TableStatus::Incomplete)
        ->and($result->registry->byName('default', 'members'))->not->toBe([]);
});

it('tombstones the old lineage and starts a fresh one on drop then recreate', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->string("legacy"); });
        Schema::drop("users");
        Schema::create("users", function ($t) { $t->id(); });
    }
};',
    ]);

    $matches = $result->registry->byName('default', 'users');
    usort($matches, static fn (TableState $a, TableState $b): int => $a->lineageId <=> $b->lineageId);

    expect(count($matches))->toBe(2)
        ->and($matches[0]->existence)->toBe(Existence::Absent)
        ->and($matches[0]->droppedBy)->not->toBeNull()
        ->and($matches[0]->status)->toBe(TableStatus::Complete)
        ->and($matches[1]->existence)->toBe(Existence::Present)
        ->and($matches[1]->status)->toBe(TableStatus::Complete)
        ->and($matches[1]->origin)->toBe(Origin::CreatedHere)
        ->and(array_keys($matches[1]->columns))->toBe(['id'])
        ->and($matches[1]->lineageId)->toBe(2);
});

it('does not apply a rename onto a live name and marks both incomplete', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->id(); });
        Schema::create("members", function ($t) { $t->id(); });
        Schema::rename("users", "members");
    }
};',
    ]);

    $users = ReplayFixture::lineage($result, 'users');
    $members = ReplayFixture::lineage($result, 'members');

    expect($users->name)->toBe('users')
        ->and($users->status)->toBe(TableStatus::Incomplete)
        ->and($members->status)->toBe(TableStatus::Incomplete)
        ->and(ReplayFixture::hasReason($users, IncompletenessOrigin::Conflict, DiagnosticCode::RenameTargetConflict))->toBeTrue()
        ->and(ReplayFixture::hasReason($members, IncompletenessOrigin::Conflict, DiagnosticCode::RenameTargetConflict))->toBeTrue()
        ->and(ReplayFixture::hasDiagnostic($result, DiagnosticCode::RenameTargetConflict))->toBeTrue();
});

it('creates unknown-origin phantom tables for alters without creates', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::table("legacy", function ($t) { $t->string("note"); });
    }
};',
    ]);

    $legacy = ReplayFixture::lineage($result, 'legacy');

    expect($legacy->origin)->toBe(Origin::Unknown)
        ->and($legacy->existence)->toBe(Existence::Present)
        ->and($legacy->status)->toBe(TableStatus::Incomplete)
        ->and(ReplayFixture::hasReason($legacy, IncompletenessOrigin::Conflict, DiagnosticCode::AlterUnknownTable))->toBeTrue()
        ->and(ReplayFixture::hasDiagnostic($result, DiagnosticCode::AlterUnknownTable))->toBeTrue()
        ->and(array_keys($legacy->columns))->toBe(['note']);
});

it('retains the phantom uncertainty when its table is created later', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::table("legacy", function ($t) { $t->string("note"); });
        Schema::create("legacy", function ($t) { $t->id(); });
    }
};',
    ]);

    $legacy = array_values(array_filter(
        $result->registry->partition('default'),
        static fn (TableState $lineage): bool => $lineage->name === 'legacy',
    ));

    expect(count($legacy))->toBe(1)
        ->and($legacy[0]->origin)->toBe(Origin::Unknown)
        ->and($legacy[0]->status)->toBe(TableStatus::Incomplete)
        ->and(ReplayFixture::hasReason($legacy[0], IncompletenessOrigin::Conflict, DiagnosticCode::AlterUnknownTable))->toBeTrue()
        ->and(array_keys($legacy[0]->columns))->toBe(['note', 'id']);
});

it('warns for drops and renames without any known target', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::drop("ghosts");
        Schema::dropIfExists("spirits");
        Schema::rename("ghosts", "spirits");
    }
};',
    ]);

    expect(ReplayFixture::hasDiagnostic($result, DiagnosticCode::DropUnknownTable))->toBeTrue()
        ->and(ReplayFixture::hasDiagnostic($result, DiagnosticCode::RenameUnknownTable))->toBeTrue()
        ->and($result->registry->partitions())->toBe([])
        ->and(count($result->events))->toBe(3);
});

it('replays column renames and change events with sticky reduced fidelity', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->string("user_email", 40); });
        Schema::table("users", function ($t) { $t->renameColumn("user_email", "email"); });
        Schema::table("users", function ($t) { $t->string("email", 90)->nullable()->change(); });
    }
};',
    ]);

    $users = ReplayFixture::lineage($result, 'users');
    $email = $users->columns['email'];

    expect($users->status)->toBe(TableStatus::Complete)
        ->and($users->fidelity())->toBe(Fidelity::Reduced)
        ->and($email->formerNames)->toBe(['user_email'])
        ->and($email->length)->toBe(90)
        ->and($email->nullable)->toBeTrue()
        ->and($email->fidelity)->toBe(Fidelity::Reduced)
        ->and($email->existence)->toBe(Existence::Present);
});

it('marks nested unresolved effects on their own lineage', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) {
            $t->id();
            $t->string("custom")->storedAs("computed");
        });
    }
};',
    ]);

    $users = ReplayFixture::lineage($result, 'users');

    expect($users->status)->toBe(TableStatus::Incomplete)
        ->and(ReplayFixture::hasReason($users, IncompletenessOrigin::Direct, DiagnosticCode::UnresolvedUnknownMethod))->toBeTrue()
        ->and(count($result->ledger->entries()))->toBe(1)
        ->and($users->columns)->toHaveKey('id');
});

it('propagates reduced fidelity from tier two columns to the table', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->id(); $t->uuid("token"); });
    }
};',
    ]);

    expect(ReplayFixture::lineage($result, 'users')->fidelity())->toBe(Fidelity::Reduced)
        ->and(ReplayFixture::lineage($result, 'users')->status)->toBe(TableStatus::Complete);
});

it('keeps conditional column lifecycle operations uncertain', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->id(); $t->string("email"); });
    }
};',
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function shouldRun(): bool
    {
        return true;
    }
    public function up(): void
    {
        Schema::table("users", function ($t) {
            $t->renameColumn("email", "mail");
            $t->string("phone");
            $t->dropColumn("phone");
        });
    }
};',
    ]);

    $users = ReplayFixture::lineage($result, 'users');
    $email = $users->columns['email'];

    expect($email->possibleNames)->toBe(['email', 'mail'])
        ->and($email->nameCertain)->toBeFalse()
        ->and($email->existence)->toBe(Existence::Present)
        ->and($users->columns['phone']->existence)->toBe(Existence::Unknown)
        ->and($users->columns['phone']->droppedBy)->toBeNull()
        ->and($users->status)->toBe(TableStatus::Incomplete)
        ->and(ReplayFixture::hasReason($users, IncompletenessOrigin::Conditional, DiagnosticCode::ShouldRunDetected))->toBeTrue();
});

it('associates multi-target body events with every compatible candidate', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->id(); });
    }
};',
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function shouldRun(): bool
    {
        return true;
    }
    public function up(): void
    {
        Schema::dropIfExists("users");
    }
};',
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->string("name"); });
    }
};',
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::table("users", function ($t) { $t->string("email"); });
    }
};',
    ]);

    $candidates = $result->registry->byName('default', 'users');

    expect(count($candidates))->toBe(2)
        ->and(count($result->events))->toBe(7)
        ->and($candidates[0]->columns)->toHaveKey('email')
        ->and($candidates[1]->columns)->toHaveKey('email')
        // The conditionally dropped identity carries its possible drop;
        // the recreated identity carries its create and the shared alter.
        ->and(count($result->lineageEvents($candidates[0]->lineageId)))->toBe(5)
        ->and(count($result->lineageEvents($candidates[1]->lineageId)))->toBe(4);
});
