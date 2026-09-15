<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah 'admin' ke daftar role yang sah.
     *
     * Versi awal menjalankan ALTER TABLE ... MODIFY COLUMN mentah, yang hanya
     * dikenal MySQL. Akibatnya migrasi berhenti di sini pada SQLite — yang
     * kebetulan adalah default DB_CONNECTION di .env.example — sehingga
     * seluruh test suite tidak punya database untuk berjalan.
     *
     * ENUM sendiri memang tidak portabel. Yang portabel adalah memeriksa
     * driver dan hanya mengubah kolom di tempat yang butuh: pada SQLite,
     * kolom string tidak mengenal ENUM dan tidak perlu diapa-apakan.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('buyer', 'seller', 'admin') NOT NULL DEFAULT 'buyer'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('buyer', 'seller') NOT NULL DEFAULT 'buyer'");
    }
};
