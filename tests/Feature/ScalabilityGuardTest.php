<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScalabilityGuardTest extends TestCase
{
    use RefreshDatabase;

    private function category(): ProductCategory
    {
        $parent = ProductCategory::create(['name' => 'Induk', 'sort_order' => 0]);

        return ProductCategory::create([
            'name' => 'Anak',
            'parent_id' => $parent->id,
            'sort_order' => 0,
        ]);
    }

    public function test_per_page_dibatasi_maksimum(): void
    {
        $category = $this->category();
        $seller = User::factory()->seller()->create();

        foreach (range(1, 3) as $i) {
            Product::create([
                'seller_id' => $seller->id,
                'category_id' => $category->id,
                'name' => "Produk {$i}",
                'price' => '1000.00',
                'stock' => 5,
                'status' => 'active',
            ]);
        }

        $response = $this->getJson('/api/products?per_page=1000000')->assertOk();

        $this->assertSame(100, $response->json('meta.per_page'));
    }

    public function test_per_page_tidak_masuk_akal_jatuh_ke_default(): void
    {
        foreach (['abc', '0', '-5', ''] as $value) {
            $this->getJson('/api/products?per_page=' . $value)
                ->assertOk()
                ->assertJsonPath('meta.per_page', 12);
        }
    }

    public function test_per_page_wajar_tetap_dihormati(): void
    {
        $this->getJson('/api/products?per_page=25')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    public function test_statistik_seller_tidak_memuat_pesanan_ke_memori(): void
    {
        $seller = User::factory()->seller()->create();
        $buyer = User::factory()->create();

        foreach (range(1, 5) as $i) {
            Transaction::create([
                'invoice_number' => 'INV-' . Str::random(6),
                'buyer_id' => $buyer->id,
                'seller_id' => $seller->id,
                'seller_name' => 'Toko',
                'status' => $i === 1 ? 'cancelled' : 'completed',
                'total_amount' => '100000.00',
                'paid_at' => now()->subDays(3),
                'shipped_at' => now()->subDays(3)->addHours($i === 2 ? 72 : 5),
            ]);
        }

        Sanctum::actingAs($seller);

        // Jumlah baris yang dibaca tidak boleh tumbuh bersama jumlah pesanan.
        DB::enableQueryLog();
        $response = $this->getJson('/api/seller/stats?range=30')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($queries as $query) {
            $this->assertStringNotContainsString(
                'select * from "transactions"',
                $query['query'],
                'Statistik harus mengagregasi di SQL, bukan menarik baris pesanan.'
            );
        }

        $health = $response->json('data.store_health');

        // 4 dari 5 pesanan tidak dibatalkan
        $this->assertEquals(80, $health['breakdown']['order_fulfilment']);
        // 3 dari 4 pesanan terkirim masuk tenggat 48 jam
        $this->assertEquals(75, $health['breakdown']['on_time_processing']);
    }

    public function test_daftar_penarikan_admin_terurut_tanpa_field_mysql(): void
    {
        $admin = User::factory()->admin()->create();
        $seller = User::factory()->seller()->create();

        foreach (['completed', 'pending', 'rejected', 'processing'] as $i => $status) {
            Withdrawal::create([
                'seller_id' => $seller->id,
                'reference' => 'WD-' . Str::random(6),
                'amount' => '50000.00',
                'status' => $status,
                'bank_name' => 'BCA',
                'bank_account_number' => '1234567890',
                'bank_account_holder' => 'Seller',
            ]);
        }

        Sanctum::actingAs($admin);

        $statuses = collect($this->getJson('/api/admin/withdrawals')->assertOk()->json('data'))
            ->pluck('status')
            ->all();

        $this->assertSame(['pending', 'processing', 'completed', 'rejected'], $statuses);
    }

    public function test_nomor_rekening_tidak_bocor_di_daftar_penarikan(): void
    {
        $admin = User::factory()->admin()->create();
        $seller = User::factory()->seller()->create();

        Withdrawal::create([
            'seller_id' => $seller->id,
            'reference' => 'WD-BOCOR',
            'amount' => '50000.00',
            'status' => 'pending',
            'bank_name' => 'BCA',
            'bank_account_number' => '9876543210',
            'bank_account_holder' => 'Seller',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/withdrawals')
            ->assertOk()
            ->assertDontSee('9876543210');
    }
}
