<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayoutAccountEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_nomor_rekening_toko_tidak_tersimpan_polos(): void
    {
        $seller = User::factory()->seller()->create();

        $store = Store::create([
            'seller_id' => $seller->id,
            'name' => 'Toko Uji',
            'bank_name' => 'BCA',
            'bank_account_number' => '1234567890',
            'bank_account_holder' => 'Seller',
        ]);

        $raw = DB::table('stores')->where('id', $store->id)->value('bank_account_number');

        $this->assertNotSame('1234567890', $raw);
        $this->assertStringNotContainsString('1234567890', $raw);
        $this->assertSame('1234567890', Crypt::decryptString($raw));

        // Aplikasi tetap membacanya seperti biasa.
        $this->assertSame('1234567890', $store->fresh()->bank_account_number);
    }

    public function test_masking_tetap_menampilkan_empat_digit_asli(): void
    {
        $seller = User::factory()->seller()->create();

        $store = Store::create([
            'seller_id' => $seller->id,
            'name' => 'Toko Uji',
            'bank_name' => 'BCA',
            'bank_account_number' => '1234568820',
            'bank_account_holder' => 'Seller',
        ]);

        $this->assertSame('•••• 8820', $store->fresh()->masked_account_number);
    }

    public function test_penarikan_menyimpan_rekening_terenkripsi_dan_admin_tetap_bisa_membacanya(): void
    {
        $seller = User::factory()->seller()->create();
        $admin = User::factory()->admin()->create();

        $withdrawal = Withdrawal::create([
            'seller_id' => $seller->id,
            'reference' => 'WD-UJI-1',
            'amount' => '75000.00',
            'status' => 'pending',
            'bank_name' => 'BCA',
            'bank_account_number' => '5566778899',
            'bank_account_holder' => 'Seller',
        ]);

        $raw = DB::table('withdrawals')->where('id', $withdrawal->id)->value('bank_account_number');
        $this->assertStringNotContainsString('5566778899', $raw);

        Sanctum::actingAs($admin);

        // Admin yang akan melakukan transfer tetap perlu nomor lengkapnya.
        $this->getJson("/api/admin/withdrawals/{$withdrawal->id}")
            ->assertOk()
            ->assertJsonPath('data.bank_account_number', '5566778899')
            ->assertJsonPath('data.masked_account_number', '•••• 8899');
    }

    public function test_migrasi_mengenkripsi_baris_lama_yang_masih_polos(): void
    {
        $seller = User::factory()->seller()->create();

        // Baris peninggalan sebelum enkripsi ada: ditulis langsung, melewati
        // model, jadi isinya benar-benar polos seperti data produksi hari ini.
        $id = DB::table('stores')->insertGetId([
            'seller_id' => $seller->id,
            'name' => 'Toko Lama',
            'slug' => 'toko-lama',
            'bank_name' => 'BNI',
            'bank_account_number' => '1112223334',
            'bank_account_holder' => 'Seller Lama',
            'is_open' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_09_15_000003_encrypt_bank_account_numbers.php');
        $migration->up();

        $raw = DB::table('stores')->where('id', $id)->value('bank_account_number');
        $this->assertStringNotContainsString('1112223334', $raw);
        $this->assertSame('1112223334', Store::find($id)->bank_account_number);

        // Aman dijalankan ulang: tidak dienkripsi dua kali.
        $migration->up();
        $this->assertSame('1112223334', Store::find($id)->bank_account_number);
    }
}
