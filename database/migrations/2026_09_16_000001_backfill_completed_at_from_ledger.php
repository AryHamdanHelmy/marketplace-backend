<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Isi kembali completed_at yang hilang karena salah ketik fillable.
     *
     * Transaction::$fillable menulis 'complated_at' — kolom yang tidak ada —
     * sehingga completed_at tidak pernah lolos mass assignment. Setiap pesanan
     * yang selesai sejak kolom itu dibuat punya completed_at NULL, dan jejak
     * kapan uang dilepas ke seller hilang.
     *
     * Momen itu masih terekam di tempat lain: BalanceLog mencatat kredit untuk
     * transaksi tersebut, dan kreditnya terjadi di dalam transaksi database
     * yang sama dengan penyelesaian pesanan. created_at ledger itu karena itu
     * adalah waktu penyelesaian yang sebenarnya, bukan perkiraan.
     *
     * Pesanan selesai yang tidak punya entri kredit dibiarkan NULL. Lebih baik
     * kosong dan jujur daripada diisi updated_at yang bisa saja bergeser
     * karena perubahan lain setelahnya.
     */
    public function up(): void
    {
        DB::table('transactions')
            ->where('status', 'completed')
            ->whereNull('completed_at')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $creditedAt = DB::table('balance_logs')
                        ->where('transaction_id', $row->id)
                        ->where('type', 'credit')
                        ->orderBy('id')
                        ->value('created_at');

                    if (!$creditedAt) {
                        continue;
                    }

                    DB::table('transactions')
                        ->where('id', $row->id)
                        ->update(['completed_at' => $creditedAt]);
                }
            });
    }

    /**
     * Tidak ada yang dikembalikan.
     *
     * Mengosongkan lagi completed_at berarti membuang data yang benar, dan
     * migrasi ini tidak bisa membedakan baris yang diisinya dari baris yang
     * terisi normal setelah salah ketiknya diperbaiki.
     */
    public function down(): void
    {
        //
    }
};
