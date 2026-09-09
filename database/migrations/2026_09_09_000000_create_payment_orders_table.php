<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A payment gateway charges once for the whole cart, while a
        // multi-seller checkout produces one `transactions` row per seller.
        // This table is the single charge that covers them all — the buyer
        // scans one QR, and the webhook settles every order in the group.
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();

            // One charge per checkout group. Unique, so a double-submit can't
            // create two bills for the same cart.
            $table->uuid('checkout_group_id')->unique();

            $table->foreignId('buyer_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Our own id, the one sent to the gateway as its order reference.
            // Never reuse a transaction id here: gateways reject duplicates
            // forever, so a retried checkout needs a fresh reference.
            $table->string('reference', 64)->unique();

            $table->decimal('amount', 12, 2);

            // Which integration handled this: 'manual', 'midtrans', 'xendit'.
            // Stored per row rather than read from config, so historical
            // records stay readable after the app switches provider.
            $table->string('gateway', 32)->default('manual');

            // The product within that gateway: 'qris', 'gopay', 'bca_va',
            // 'credit_card', 'bank_transfer'. Free text because every
            // provider names these differently.
            $table->string('channel', 40)->nullable();

            $table->enum('status', [
                'pending',           // created, nothing sent yet
                'awaiting_payment',  // gateway issued instructions
                'paid',
                'expired',
                'failed',
                'refunded',
            ])->default('pending');

            // The gateway's own id for this charge, returned on creation and
            // echoed back in webhooks.
            $table->string('gateway_reference', 128)->nullable()->index();

            // What the buyer needs to act on: a QR string, a VA number, a
            // redirect URL. Shape differs per channel, so it's kept as JSON.
            $table->json('instructions')->nullable();

            // Last raw payload from the gateway. Invaluable when a payment
            // goes wrong and support has to reconstruct what happened.
            $table->json('payload')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            // The expiry sweep scans on these two together
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_orders');
    }
};
