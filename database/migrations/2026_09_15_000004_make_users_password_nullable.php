<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Someone who only ever signs in with Google or Apple has no password of
    // ours to store, and a blank-string placeholder would be a password hash
    // that Hash::check could conceivably be coaxed into accepting. Null says
    // "this account has no password" unambiguously.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
