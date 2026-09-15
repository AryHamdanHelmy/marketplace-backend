<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->setRoles(['buyer', 'seller', 'admin']);
    }

    public function down(): void
    {
        $this->setRoles(['buyer', 'seller']);
    }

    // MySQL gets the raw ALTER it has always had. Other drivers — the sqlite
    // database the test suite runs on, above all — go through the schema
    // builder, which knows how to rebuild the table.
    private function setRoles(array $roles): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $list = implode(', ', array_map(static fn (string $r) => "'{$r}'", $roles));

            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM({$list}) NOT NULL DEFAULT 'buyer'");

            return;
        }

        Schema::table('users', function (Blueprint $table) use ($roles) {
            $table->enum('role', $roles)->default('buyer')->change();
        });
    }
};
