<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Contracts\Encryption\DecryptException;

return new class extends Migration
{
    /**
     * Enkripsi nomor rekening payout yang tersimpan.
     *
     * Masking selama ini hanya terjadi di lapisan serialisasi — di database
     * nilainya polos. Satu dump, satu backup yang bocor, atau satu SQL
     * injection di mana pun membuka seluruh rekening payout seller.
     *
     * Ciphertext jauh lebih panjang dari 50 karakter, jadi kolomnya dilebarkan
     * ke TEXT lebih dulu. Baris yang sudah terenkripsi dilewati, supaya
     * migrasi ini aman dijalankan ulang.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->text('bank_account_number')->nullable()->change();
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->text('bank_account_number')->change();
        });

        $this->encryptColumn('stores');
        $this->encryptColumn('withdrawals');
    }

    /**
     * Kembalikan ke bentuk terbaca.
     *
     * Nilai yang tidak bisa didekripsi — misalnya karena APP_KEY sudah
     * berganti — dibiarkan apa adanya daripada ditimpa dengan tebakan.
     */
    public function down(): void
    {
        foreach (['stores', 'withdrawals'] as $table) {
            DB::table($table)
                ->whereNotNull('bank_account_number')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        try {
                            $plain = Crypt::decryptString($row->bank_account_number);
                        } catch (DecryptException) {
                            continue;
                        }

                        DB::table($table)
                            ->where('id', $row->id)
                            ->update(['bank_account_number' => $plain]);
                    }
                });
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->string('bank_account_number', 50)->nullable()->change();
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->string('bank_account_number', 50)->change();
        });
    }

    private function encryptColumn(string $table): void
    {
        DB::table($table)
            ->whereNotNull('bank_account_number')
            ->where('bank_account_number', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $value = $row->bank_account_number;

                    // Sudah terenkripsi dari jalan sebelumnya: jangan
                    // dienkripsi dua kali.
                    try {
                        Crypt::decryptString($value);

                        continue;
                    } catch (DecryptException) {
                        // memang masih polos — lanjut enkripsi
                    }

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['bank_account_number' => Crypt::encryptString($value)]);
                }
            });
    }
};
