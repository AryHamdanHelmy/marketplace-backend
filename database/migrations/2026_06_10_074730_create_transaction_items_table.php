<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            // Buang foreign key lama (restrictOnDelete)
            $table->dropForeign(['product_id']);
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->change();

            // Pasang ulang: produk boleh dihapus, kolom jadi NULL, snapshot tetap utuh
            $table->foreign('product_id')
                  ->references('id')->on('products')
                  ->nullOnDelete();
        });

        // Sekalian tambah thumbnail snapshot
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->string('product_thumbnail')->nullable()->after('product_name');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn('product_thumbnail');
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable(false)->change();
            $table->foreign('product_id')
                  ->references('id')->on('products')
                  ->restrictOnDelete();
        });
    }
};