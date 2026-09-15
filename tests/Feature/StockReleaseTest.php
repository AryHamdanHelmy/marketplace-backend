<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\PaymentOrder;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockReleaseTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $stock = 10): Product
    {
        $parent = ProductCategory::create(['name' => 'Induk', 'sort_order' => 0]);
        $category = ProductCategory::create([
            'name' => 'Anak',
            'parent_id' => $parent->id,
            'sort_order' => 0,
        ]);

        return Product::create([
            'seller_id' => User::factory()->seller()->create()->id,
            'category_id' => $category->id,
            'name' => 'Mouse',
            'price' => '150000.00',
            'stock' => $stock,
            'status' => 'active',
        ]);
    }

    /**
     * Membuat pesanan 'pending' yang menahan stok, persis seperti yang
     * ditinggalkan POST /checkout sebelum pembeli memilih pembayaran.
     */
    private function pendingOrder(Product $product, int $qty, ?string $groupId = null): Transaction
    {
        $order = Transaction::create([
            'checkout_group_id' => $groupId ?? (string) Str::uuid(),
            'invoice_number' => 'INV-' . Str::random(6),
            'buyer_id' => User::factory()->create()->id,
            'seller_id' => $product->seller_id,
            'seller_name' => 'Toko',
            'status' => 'pending',
            'total_amount' => '150000.00',
        ]);

        TransactionItem::create([
            'transaction_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => $product->price,
            'quantity' => $qty,
            'subtotal' => '150000.00',
        ]);

        $product->decrement('stock', $qty);

        return $order;
    }

    public function test_checkout_terbengkalai_mengembalikan_stok(): void
    {
        $product = $this->product(10);
        $order = $this->pendingOrder($product, 3);
        $order->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->assertSame(7, $product->fresh()->stock);

        $this->artisan('payments:expire')->assertSuccessful();

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_checkout_yang_masih_punya_tagihan_hidup_tidak_disentuh(): void
    {
        $product = $this->product(10);
        $groupId = (string) Str::uuid();
        $order = $this->pendingOrder($product, 3, $groupId);
        $order->forceFill(['created_at' => now()->subDays(3)])->save();

        PaymentOrder::create([
            'checkout_group_id' => $groupId,
            'buyer_id' => $order->buyer_id,
            'reference' => PaymentOrder::generateReference(),
            'amount' => '150000.00',
            'gateway' => 'midtrans',
            'channel' => 'qris',
            'status' => 'awaiting_payment',
            'expires_at' => now()->addHours(6),
        ]);

        $this->artisan('payments:expire')->assertSuccessful();

        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_checkout_yang_baru_dibuat_tidak_disentuh(): void
    {
        $product = $this->product(10);
        $order = $this->pendingOrder($product, 3);

        $this->artisan('payments:expire')->assertSuccessful();

        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_pesanan_lunas_tidak_ikut_dibatalkan_sapuan(): void
    {
        $product = $this->product(10);
        $order = $this->pendingOrder($product, 3);
        $order->forceFill([
            'created_at' => now()->subDays(3),
            'status' => 'paid',
        ])->save();

        $this->artisan('payments:expire')->assertSuccessful();

        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_pembeli_tidak_bisa_membatalkan_pesanan_yang_sudah_dibayar(): void
    {
        $product = $this->product(10);
        $order = $this->pendingOrder($product, 3);
        $order->forceFill(['status' => 'paid'])->save();

        Sanctum::actingAs(User::find($order->buyer_id));

        $this->postJson("/api/orders/{$order->id}/cancel")->assertStatus(422);

        $order->refresh();
        $this->assertSame('paid', $order->status);
        // Yang penting: stok tidak kembali, karena barangnya memang sudah terjual.
        $this->assertSame(7, $product->fresh()->stock);
    }

    public function test_pembeli_masih_bisa_membatalkan_pesanan_yang_belum_dibayar(): void
    {
        $product = $this->product(10);
        $order = $this->pendingOrder($product, 3);

        Sanctum::actingAs(User::find($order->buyer_id));

        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $product->fresh()->stock);
    }
}
