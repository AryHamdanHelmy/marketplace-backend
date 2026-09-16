<?php

namespace Tests\Feature;

use App\Models\PaymentOrder;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ConcurrencyGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_klik_ganda_pada_charge_tidak_berakhir_500(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->seller()->create();
        $groupId = (string) Str::uuid();

        Transaction::create([
            'checkout_group_id' => $groupId,
            'invoice_number' => 'INV-' . Str::random(6),
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'seller_name' => 'Toko',
            'status' => 'pending',
            'total_amount' => '100000.00',
            'shipping_cost' => '0.00',
        ]);

        // Baris "pemenang" sudah ada tapi belum sempat ditagihkan — persis
        // keadaan di sela antara dua klik yang datang bersamaan.
        PaymentOrder::create([
            'checkout_group_id' => $groupId,
            'buyer_id' => $buyer->id,
            'reference' => PaymentOrder::generateReference(),
            'amount' => '100000.00',
            'gateway' => 'manual',
            'channel' => 'bank_transfer',
            'status' => 'pending',
        ]);

        // Klik kedua: cabang reuse melayaninya karena charge pemenang masih
        // payable dan channelnya sama. Yang penting, bukan 500.
        $order = app(PaymentService::class)
            ->createCharge($groupId, $buyer->id, 'bank_transfer');

        $this->assertSame($groupId, $order->checkout_group_id);
        $this->assertSame(1, PaymentOrder::where('checkout_group_id', $groupId)->count());
    }

    public function test_dua_penambahan_produk_yang_sama_tidak_menabrak_unique(): void
    {
        $seller = User::factory()->seller()->create();
        $buyer = User::factory()->create();
        $parent = ProductCategory::create(['name' => 'Induk', 'sort_order' => 0]);
        $category = ProductCategory::create([
            'name' => 'Anak', 'parent_id' => $parent->id, 'sort_order' => 0,
        ]);

        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'name' => 'Mouse',
            'price' => '150000.00',
            'stock' => 10,
            'status' => 'active',
        ]);

        Sanctum::actingAs($buyer);

        $this->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 2])
            ->assertCreated();
        $this->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 3])
            ->assertCreated();

        $this->assertSame(1, \App\Models\CartItem::where('user_id', $buyer->id)->count());
        $this->assertSame(5, \App\Models\CartItem::where('user_id', $buyer->id)->value('quantity'));
    }
}
