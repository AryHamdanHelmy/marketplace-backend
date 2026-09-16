<?php

namespace Tests\Feature;

use App\Models\BalanceLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function shippedOrder(User $buyer, User $seller): Transaction
    {
        return Transaction::create([
            'invoice_number' => 'INV-' . Str::random(6),
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'seller_name' => $seller->name,
            'status' => 'shipped',
            'total_amount' => '200000.00',
            'paid_at' => now()->subDays(2),
            'shipped_at' => now()->subDay(),
        ]);
    }

    public function test_konfirmasi_penerimaan_menyimpan_completed_at(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->seller()->create();
        $order = $this->shippedOrder($buyer, $seller);

        Sanctum::actingAs($buyer);

        $this->postJson("/api/orders/{$order->id}/confirm")->assertOk();

        $order->refresh();

        // Inti temuan #12: sebelum perbaikan nilai ini selalu NULL karena
        // fillable menyebut 'complated_at'.
        $this->assertNotNull($order->completed_at);
        $this->assertSame('buyer', $order->completed_by);
        $this->assertSame('completed', $order->status);
    }

    public function test_job_auto_complete_juga_menyimpan_completed_at(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->seller()->create();
        $order = $this->shippedOrder($buyer, $seller);
        $order->forceFill(['shipped_at' => now()->subDays(10)])->save();

        $this->artisan('orders:auto-complete')->assertSuccessful();

        $order->refresh();

        $this->assertNotNull($order->completed_at);
        $this->assertSame('system', $order->completed_by);
    }

    public function test_migrasi_mengisi_completed_at_dari_ledger(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->seller()->create();

        $order = $this->shippedOrder($buyer, $seller);
        // Pesanan peninggalan: sudah selesai, sudah dikredit, tapi
        // completed_at-nya hilang karena salah ketik fillable.
        DB::table('transactions')->where('id', $order->id)->update([
            'status' => 'completed',
            'completed_at' => null,
            'completed_by' => 'buyer',
        ]);

        $creditedAt = now()->subDays(5)->startOfSecond();
        BalanceLog::create([
            'seller_id' => $seller->id,
            'transaction_id' => $order->id,
            'type' => 'credit',
            'amount' => '200000.00',
            'note' => 'Order selesai',
        ]);
        DB::table('balance_logs')->where('transaction_id', $order->id)
            ->update(['created_at' => $creditedAt]);

        $migration = require database_path('migrations/2026_09_16_000001_backfill_completed_at_from_ledger.php');
        $migration->up();

        $this->assertSame(
            $creditedAt->toDateTimeString(),
            $order->fresh()->completed_at->toDateTimeString()
        );
    }

    public function test_pesanan_selesai_tanpa_entri_kredit_dibiarkan_kosong(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->seller()->create();
        $order = $this->shippedOrder($buyer, $seller);

        DB::table('transactions')->where('id', $order->id)->update([
            'status' => 'completed',
            'completed_at' => null,
        ]);

        $migration = require database_path('migrations/2026_09_16_000001_backfill_completed_at_from_ledger.php');
        $migration->up();

        $this->assertNull($order->fresh()->completed_at);
    }

    public function test_seller_tidak_bisa_mengatur_rating_produknya_sendiri(): void
    {
        $seller = User::factory()->seller()->create();
        $parent = ProductCategory::create(['name' => 'Induk', 'sort_order' => 0]);
        $category = ProductCategory::create([
            'name' => 'Anak', 'parent_id' => $parent->id, 'sort_order' => 0,
        ]);

        Sanctum::actingAs($seller);

        $this->postJson('/api/products', [
            'category_id' => $category->id,
            'name' => 'Produk Curang',
            'price' => 10000,
            'stock' => 5,
            'rating' => 5,
            'download_count' => 9999,
        ])->assertCreated();

        $product = Product::where('name', 'Produk Curang')->first();

        $this->assertNotEquals(5, (float) $product->rating);
        $this->assertNotEquals(9999, (int) $product->download_count);
    }

    public function test_seller_tidak_bisa_menaikkan_rating_lewat_update(): void
    {
        $seller = User::factory()->seller()->create();
        $parent = ProductCategory::create(['name' => 'Induk', 'sort_order' => 0]);
        $category = ProductCategory::create([
            'name' => 'Anak', 'parent_id' => $parent->id, 'sort_order' => 0,
        ]);

        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'name' => 'Produk Jujur',
            'price' => '10000.00',
            'stock' => 5,
            'status' => 'active',
        ]);

        $ratingAwal = $product->rating;

        Sanctum::actingAs($seller);

        $this->putJson("/api/products/{$product->id}", [
            'name' => 'Produk Jujur',
            'rating' => 5,
            'download_count' => 4321,
        ])->assertOk();

        $product->refresh();

        $this->assertEquals($ratingAwal, $product->rating);
        $this->assertNotEquals(4321, (int) $product->download_count);
    }
}
