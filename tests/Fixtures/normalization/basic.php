<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('users', function ($bp) {
            $bp->id();
            $bp->string('name', 80)->nullable()->default('guest');
            $bp->integer('rank')->default(-2);
            $bp->boolean('active')->default(false);
            $bp->text('bio')->default(null);
            $bp->timestamps(3);
        });
        Schema::table('users', function ($bp) {
            $bp->dropColumn(['old', 'unused']);
            $bp->renameColumn('name', 'full_name');
        });
        Schema::rename('users', 'members');
        Schema::connection('archive')->dropIfExists('users');
        Schema::drop('members');
    }
};
