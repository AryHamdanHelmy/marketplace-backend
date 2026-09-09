<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // "Home", "Office" — how the buyer tells their addresses apart
            $table->string('label', 50)->nullable();

            // Recipient, not account holder. Gifts and office deliveries go to
            // someone else, and the courier calls this name and number.
            $table->string('recipient_name', 150);
            $table->string('phone', 25);

            $table->text('street');
            $table->string('district', 100)->nullable();   // kecamatan
            $table->string('city', 100);
            $table->string('province', 100);
            $table->string('postal_code', 10)->nullable();

            // "Leave with the security post by the wooden gate."
            $table->string('courier_note', 255)->nullable();

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            // The picker loads a buyer's addresses with the default first
            $table->index(['user_id', 'is_default']);
        });

        // Where the parcel was actually sent. Copied onto the order rather
        // than referenced, because a buyer editing their address later must
        // not rewrite the history of orders already shipped.
        Schema::table('transactions', function (Blueprint $table) {
            $table->json('shipping_address')->nullable()->after('checkout_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('shipping_address');
        });

        Schema::dropIfExists('addresses');
    }
};
