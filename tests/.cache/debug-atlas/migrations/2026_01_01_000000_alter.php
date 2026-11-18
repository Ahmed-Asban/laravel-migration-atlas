<?php
use Illuminate\Support\Facades\Schema;
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        Schema::table("legacy", function ($t) { $t->string("note"); });
    }
};