<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indeks untuk katalog publik.
     *
     * GET /api/products memfilter status, mengurutkan created_at/price/rating,
     * dan mencari nama dengan LIKE '%kata%'. Tabel products tidak punya satu
     * pun indeks untuk itu, jadi setiap kunjungan halaman produk — endpoint
     * paling ramai di aplikasi ini — adalah full table scan.
     *
     * FULLTEXT hanya dibuat di MySQL. LIKE dengan wildcard di depan tidak bisa
     * memakai indeks apa pun; beralih ke FULLTEXT adalah pekerjaan tersendiri
     * di sisi query, dan indeks ini menyiapkan jalannya tanpa mengubah
     * perilaku pencarian hari ini.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Pasangan yang dipakai hampir setiap request katalog:
            // "status = active, terbaru dulu".
            $table->index(['status', 'created_at'], 'products_status_created_index');

            // Filter harga dan rating, keduanya selalu disertai filter status.
            $table->index(['status', 'price'], 'products_status_price_index');
            $table->index(['status', 'rating'], 'products_status_rating_index');

            // Dashboard seller: "produk saya, terbaru dulu".
            $table->index(['seller_id', 'status'], 'products_seller_status_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products ADD FULLTEXT products_name_fulltext (name, description)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products DROP INDEX products_name_fulltext');
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_status_created_index');
            $table->dropIndex('products_status_price_index');
            $table->dropIndex('products_status_rating_index');
            $table->dropIndex('products_seller_status_index');
        });
    }
};
