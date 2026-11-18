<?php

declare(strict_types=1);

use MigrationAtlas\History\TableHistory;
use MigrationAtlas\History\TableResolutionKind;
use MigrationAtlas\Reconstruction\State\Existence;
use MigrationAtlas\Tests\Support\ReplayFixture;

it('resolves a single live or uncertain candidate', function () {
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
    ]);

    $resolution = (new TableHistory)->resolve($result, 'users');

    expect($resolution->kind)->toBe(TableResolutionKind::Resolved)
        ->and($resolution->primary()->name)->toBe('users')
        ->and($resolution->primary()->existence)->toBe(Existence::Present);
});

it('resolves the live lineage over certain tombstones', function () {
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

    $resolution = (new TableHistory)->resolve($result, 'users');

    expect($resolution->kind)->toBe(TableResolutionKind::Resolved)
        ->and($resolution->primary()->existence)->toBe(Existence::Present)
        ->and(array_keys($resolution->primary()->columns))->toBe(['id']);
});

it('resolves the latest tombstone when only dead lineages carry the name', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::create("users", function ($t) { $t->string("a"); });
        Schema::drop("users");
        Schema::create("users", function ($t) { $t->string("b"); });
        Schema::drop("users");
    }
};',
    ]);

    $resolution = (new TableHistory)->resolve($result, 'users');

    expect($resolution->kind)->toBe(TableResolutionKind::Tombstone)
        ->and($resolution->primary()->existence)->toBe(Existence::Absent)
        ->and($resolution->primary()->droppedBy)->not->toBeNull()
        ->and(array_keys($resolution->primary()->columns))->toBe(['b']);
});

it('reports not found when no lineage ever carried the name', function () {
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
    ]);

    $resolution = (new TableHistory)->resolve($result, 'ghosts');

    expect($resolution->kind)->toBe(TableResolutionKind::NotFound)
        ->and($resolution->primary())->toBeNull();
});

it('reports ambiguous identities without choosing a winner', function () {
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
        Schema::create("users", function ($t) { $t->id(); });
    }
};',
    ]);

    $resolution = (new TableHistory)->resolve($result, 'users');

    expect($resolution->kind)->toBe(TableResolutionKind::Ambiguous)
        ->and(count($resolution->candidates))->toBe(2)
        ->and($resolution->candidates[0]->existence)->toBe(Existence::Unknown)
        ->and($resolution->candidates[1]->existence)->toBe(Existence::Present);
});

it('resolves by former and possible names', function () {
    $result = ReplayFixture::replay([
        '<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
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
    public function shouldRun(): bool
    {
        return true;
    }
    public function up(): void
    {
        Schema::rename("members", "people");
    }
};',
    ]);

    $history = new TableHistory;

    expect($history->resolve($result, 'users')->kind)->toBe(TableResolutionKind::Resolved)
        ->and($history->resolve($result, 'users')->primary()->name)->toBe('members')
        ->and($history->resolve($result, 'people')->kind)->toBe(TableResolutionKind::Resolved)
        ->and($history->resolve($result, 'people')->primary()->possibleNames)->toContain('people');
});
