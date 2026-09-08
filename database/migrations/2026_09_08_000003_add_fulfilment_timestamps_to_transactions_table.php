<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // When the seller marked the order as shipped. The auto-complete
            // window counts from here. Once real shipping lands this stays
            // useful — it becomes the moment the parcel was handed over,
            // while courier and tracking details live in their own table.
            $table->timestamp('shipped_at')->nullable()->after('paid_at');

            // When the order reached its final state, whether the buyer
            // confirmed or the window expired. This is the moment the
            // seller's balance is credited.
            $table->timestamp('completed_at')->nullable()->after('shipped_at');

            // Records which path got it there, so a dispute can be traced.
            $table->enum('completed_by', ['buyer', 'system'])
                ->nullable()
                ->after('completed_at');

            // The scheduled job scans for shipped orders past the window.
            $table->index(['status', 'shipped_at']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['status', 'shipped_at']);
            $table->dropColumn(['shipped_at', 'completed_at', 'completed_by']);
        });
    }
};
