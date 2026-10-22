<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$GLOBALS['migration_atlas_normalization_executed'] = true;
throw new RuntimeException('Never execute migration source.');

return new class extends Migration
{
    public function shouldRun(): bool
    {
        throw new RuntimeException('Never execute shouldRun.');
    }

    public function up(): void
    {
        Schema::create('users', function ($bp) {
            $bp->id();
            $bp->uuid('token');
            $bp->text('value')->default(now());
            if ($enabled) {
                $bp->dropColumn('old');
            }
        });
        Schema::connection($connection)->rename('users', 'members');
        Schema::createIfNotExists('other', fn ($bp) => $bp->id());
        DB::unprepared('DROP TABLE arbitrary');
    }

    public function down(): void
    {
        throw new RuntimeException('Never execute down.');
    }
};
