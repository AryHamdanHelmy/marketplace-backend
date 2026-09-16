<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alur refund manual: pesanan berbayar yang dibatalkan.
     *
     * Sampai sekarang pembatalan pesanan berbayar diblokir di aplikasi dan
     * diselesaikan di luar sistem, tanpa jejak apa pun. Ini memberi jejak itu:
     * dua status baru pada pesanan, kolom yang mencatat siapa memproses dan
     * dengan bukti transfer apa, serta satu jenis entri ledger baru.
     *
     * ENUM tidak portabel. Di MySQL kolomnya diubah dengan MODIFY; di mesin
     * lain dipakai ->change(), yang membangun ulang tabelnya.
     *
     * Jangan tergoda melewati driver selain MySQL: SQLite menegakkan enum
     * lewat CHECK constraint, bukan memperlakukannya sebagai string bebas.
     * Melewatkannya membuat setiap penulisan status baru gagal dengan "CHECK
     * constraint failed" — dan itu sudah terjadi sekali di test suite ini
     * sebelum baris di bawah ditulis.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN status ENUM('pending', 'paid', 'shipped', 'completed', 'cancelled', 'refund_pending', 'refunded') NOT NULL DEFAULT 'pending'");
            DB::statement("ALTER TABLE payments MODIFY COLUMN status ENUM('pending', 'verified', 'failed', 'refunded') NOT NULL DEFAULT 'pending'");

            // 'refund' mencatat pengembalian ke pembeli. Ia tidak menggerakkan
            // saldo seller — lihat keterangannya di RefundService.
            DB::statement("ALTER TABLE balance_logs MODIFY COLUMN type ENUM('credit', 'debit', 'refund') NOT NULL");
        } else {
            Schema::table('transactions', function (Blueprint $table) {
                $table->enum('status', [
                    'pending', 'paid', 'shipped', 'completed', 'cancelled',
                    'refund_pending', 'refunded',
                ])->default('pending')->change();
            });

            Schema::table('payments', function (Blueprint $table) {
                $table->enum('status', ['pending', 'verified', 'failed', 'refunded'])
                    ->default('pending')
                    ->change();
            });

            Schema::table('balance_logs', function (Blueprint $table) {
                $table->enum('type', ['credit', 'debit', 'refund'])->change();
            });
        }

        Schema::table('transactions', function (Blueprint $table) {
            // Alasan yang dilihat pembeli, diisi admin saat menandai pesanan
            // masuk antrean refund.
            $table->string('refund_reason', 500)->nullable()->after('cancelled_at');

            // Bukti transfer dari bank. Tanpa ini sebuah refund tidak bisa
            // ditelusuri kalau pembeli bilang uangnya belum sampai.
            $table->string('refund_reference', 100)->nullable()->after('refund_reason');

            $table->timestamp('refund_requested_at')->nullable()->after('refund_reference');
            $table->timestamp('refunded_at')->nullable()->after('refund_requested_at');

            $table->foreignId('refunded_by')
                ->nullable()
                ->after('refunded_at')
                ->constrained('users')
                ->nullOnDelete();

            // Antrean admin: "yang menunggu ditransfer, paling lama dulu".
            $table->index(['status', 'refund_requested_at']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['status', 'refund_requested_at']);
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropColumn([
                'refund_reason',
                'refund_reference',
                'refund_requested_at',
                'refunded_at',
            ]);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE balance_logs MODIFY COLUMN type ENUM('credit', 'debit') NOT NULL");
            DB::statement("ALTER TABLE payments MODIFY COLUMN status ENUM('pending', 'verified', 'failed') NOT NULL DEFAULT 'pending'");
            DB::statement("ALTER TABLE transactions MODIFY COLUMN status ENUM('pending', 'paid', 'shipped', 'completed', 'cancelled') NOT NULL DEFAULT 'pending'");
        } else {
            Schema::table('balance_logs', function (Blueprint $table) {
                $table->enum('type', ['credit', 'debit'])->change();
            });

            Schema::table('payments', function (Blueprint $table) {
                $table->enum('status', ['pending', 'verified', 'failed'])
                    ->default('pending')
                    ->change();
            });

            Schema::table('transactions', function (Blueprint $table) {
                $table->enum('status', ['pending', 'paid', 'shipped', 'completed', 'cancelled'])
                    ->default('pending')
                    ->change();
            });
        }
    }
};
