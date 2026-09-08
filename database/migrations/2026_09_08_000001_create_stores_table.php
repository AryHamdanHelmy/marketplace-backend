<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();

            // One shop per seller. The unique constraint is what makes
            // $user->store a hasOne rather than a hasMany.
            $table->foreignId('seller_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('name', 150);

            // Reserved for public shop pages at /shop/{slug}. Adding it now
            // costs nothing; backfilling it later across live shops does.
            $table->string('slug', 160)->unique();

            $table->text('description')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('banner_url')->nullable();

            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();

            // Drives the Open / Closed toggle. A closed shop keeps its
            // listings visible but stops accepting new orders.
            $table->boolean('is_open')->default(true);

            // Payout destination for withdrawals. Nullable because a seller
            // can list products long before they ask to be paid.
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_number', 50)->nullable();
            $table->string('bank_account_holder', 150)->nullable();

            $table->timestamps();

            $table->index('is_open');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
