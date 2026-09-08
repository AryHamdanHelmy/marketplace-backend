<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('seller_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('reference', 50)->unique();

            $table->decimal('amount', 12, 2);

            $table->enum('status', ['pending', 'processing', 'completed', 'rejected'])
                ->default('pending');

            // Snapshot of where the money was meant to go. The seller can edit
            // their payout account at any time, so reading it from `stores`
            // later would misrepresent past transfers.
            $table->string('bank_name', 100);
            $table->string('bank_account_number', 50);
            $table->string('bank_account_holder', 150);

            // Bank transfer reference on completion, or the reason on rejection
            $table->string('transfer_reference', 100)->nullable();
            $table->text('note')->nullable();

            $table->foreignId('processed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
