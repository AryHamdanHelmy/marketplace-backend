<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrects weight_grams from "default 1000" to nullable.
 *
 * The default made every untouched product look like a deliberate 1kg, which
 * left no way to tell a seller they still had to fill it in. Silently shipping
 * a 3kg item priced as 1kg is worse than refusing to quote it.
 *
 * Nullable is the honest state: NULL means nobody has said, and the quote
 * layer already treats a zero weight as unquotable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('weight_grams')->nullable()->default(null)->change();
        });

        // Every row currently holding exactly 1000 got it from the default a
        // moment ago, not from a seller. Clearing them is what makes the
        // dashboard checklist able to see them.
        //
        // If a seller has genuinely typed 1000 since the first migration ran,
        // they'll be asked once more. That trade is worth it — the alternative
        // is a shop that quietly under-quotes every parcel.
        DB::table('products')->where('weight_grams', 1000)->update([
            'weight_grams' => null,
        ]);
    }

    public function down(): void
    {
        DB::table('products')->whereNull('weight_grams')->update([
            'weight_grams' => 1000,
        ]);

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('weight_grams')->default(1000)->nullable(false)->change();
        });
    }
};
