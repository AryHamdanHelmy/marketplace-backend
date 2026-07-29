<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->uuid('checkout_group_id')->nullable()->after('id')->index();
            $table->string('invoice_number', 50)->nullable()->unique()->after('checkout_group_id');
            $table->string('seller_name', 150)->nullable()->after('seller_id');
            $table->timestamp('paid_at')->nullable()->after('total_amount');
            $table->timestamp('cancelled_at')->nullable()->after('paid_at');

            $table->index(['buyer_id', 'status']);
            $table->index(['seller_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['buyer_id', 'status']);
            $table->dropIndex(['seller_id', 'status']);
            $table->dropColumn([
                'checkout_group_id',
                'invoice_number',
                'seller_name',
                'paid_at',
                'cancelled_at',
            ]);
        });
    }
};