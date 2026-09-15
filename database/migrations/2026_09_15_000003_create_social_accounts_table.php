<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);

            // The provider's own immutable id for the person ("sub" in the
            // token). This, not the email, is what identifies a returning
            // user: people change the email on a Google account, and Apple
            // hands out relay addresses that can be turned off.
            $table->string('provider_user_id');

            // A snapshot of what the provider told us at the last sign-in,
            // kept for support questions. Never used to look an account up.
            $table->string('email')->nullable();
            $table->string('name', 100)->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
