<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('buyer', 'seller', 'admin') NOT NULL DEFAULT 'buyer'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('buyer', 'seller') NOT NULL DEFAULT 'buyer'");
    }
};