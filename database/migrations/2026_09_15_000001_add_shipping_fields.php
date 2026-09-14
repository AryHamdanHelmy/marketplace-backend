<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groundwork for courier integration.
 *
 * Four tables change, and they are kept in one migration because none of them
 * is useful alone: a rate quote needs an origin, a destination and a weight,
 * and there is nowhere to put the answer without the transaction columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Grams, not kilograms. Couriers bill per kilo but round up, so
            // storing the real gram figure and rounding at quote time is the
            // only way a basket of small items prices correctly.
            //
            // Existing rows get 1000g. That is a guess, not a fact — sellers
            // have to correct it, and the quote endpoint is what will surface
            // how wrong it is.
            $table->unsignedInteger('weight_grams')
                ->default(1000)
                ->after('price');
        });

        Schema::table('stores', function (Blueprint $table) {
            // The courier's own area identifier for where parcels ship from.
            // Kept as a string because providers disagree on whether these are
            // integers, and a leading zero lost to an int cast is a silent
            // wrong-city bug.
            $table->string('origin_area_id', 40)
                ->nullable()
                ->after('province');

            // What the seller picked, in words. Without this the settings page
            // can only show a bare code, and nobody can tell whether their
            // shop is set to the right place.
            $table->string('origin_area_label', 255)
                ->nullable()
                ->after('origin_area_id');
        });

        Schema::table('addresses', function (Blueprint $table) {
            $table->string('destination_area_id', 40)
                ->nullable()
                ->after('postal_code');

            $table->string('destination_area_label', 255)
                ->nullable()
                ->after('destination_area_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            // Shipping is held apart from total_amount rather than folded into
            // it. The seller is owed the goods, the courier is owed the
            // freight, and escrow has to release those to different places —
            // one summed column would make that split unrecoverable.
            $table->decimal('shipping_cost', 12, 2)
                ->default(0)
                ->after('total_amount');

            $table->string('courier_code', 30)->nullable()->after('shipping_cost');
            $table->string('courier_service', 60)->nullable()->after('courier_code');

            // Free text from the courier ("2-3"), not a number. Providers
            // return days, ranges, and occasionally prose.
            $table->string('courier_etd', 60)->nullable()->after('courier_service');

            $table->string('tracking_number', 60)->nullable()->after('courier_etd');

            // Last tracking payload, so the order page can render a history
            // without calling the courier on every page load.
            $table->json('tracking_snapshot')->nullable()->after('tracking_number');

            $table->timestamp('tracking_checked_at')->nullable()->after('tracking_snapshot');

            // Looking up an order by waybill happens whenever a buyer or a
            // support request arrives with nothing but the number.
            $table->index('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['tracking_number']);
            $table->dropColumn([
                'shipping_cost',
                'courier_code',
                'courier_service',
                'courier_etd',
                'tracking_number',
                'tracking_snapshot',
                'tracking_checked_at',
            ]);
        });

        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn(['destination_area_id', 'destination_area_label']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['origin_area_id', 'origin_area_label']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('weight_grams');
        });
    }
};
