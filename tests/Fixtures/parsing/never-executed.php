<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

file_put_contents(__DIR__.'/executed.flag', 'Migration source was executed.');
throw new RuntimeException('Top-level migration source must not execute.');

return new class extends Migration
{
    public function shouldRun(): bool
    {
        throw new RuntimeException('shouldRun must not execute.');
    }

    public function up(): void
    {
        Schema::create('users', fn ($table) => $table->id());

        throw new RuntimeException('up must not execute.');
    }

    public function down(): void
    {
        file_put_contents(__DIR__.'/down-executed.flag', 'down was executed.');
        Schema::drop('users');
    }
};
