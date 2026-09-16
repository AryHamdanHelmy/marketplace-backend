<?php

namespace Tests\Feature;

use App\Models\BalanceLog;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SellerBalance;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\BalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualRefundTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $seller;
    private User $buyer;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->seller = User::factory()->seller()->create();
        $this->buyer = User::factory()->create();

        $parent = ProductCategory::create(['name' => 'Induk', 'sort_order' => 0]);
        $category = ProductCategory::create([
            'name' => 'Anak', 'parent_id' => $parent->id, 'sort_order' => 0,
        ]);

        $this->product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $category->id,
            'name' => 'Mouse',
            'price' => '150000.00',
            'stock' => 7,   // 10 dikurangi 3 yang sedang dipesan
            'status' => 'active',
        ]);
    }

    private function paidOrder(string $status = 'paid'): Transaction
    {
        $groupId = (string) Str::uuid();

        $order = Transaction::create([
            'checkout_group_id' => $groupId,
            'invoice_number' => 'INV-' . Str::random(6),
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'seller_name' => 'Toko',
            'status' => $status,
            'total_amount' => '450000.00',
            'shipping_cost' => '20000.00',
            'paid_at' => now()->subDay(),
        ]);

        TransactionItem::create([
            'transaction_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'product_price' => $this->product->price,
            'quantity' => 3,
            'subtotal' => '450000.00',
        ]);

        Payment::create([
            'transaction_id' => $order->id,
            'method' => 'bank_transfer',
            'status' => 'verified',
            'amount' => '470000.00',
        ]);

        PaymentOrder::create([
            'checkout_group_id' => $groupId,
            'buyer_id' => $this->buyer->id,
            'reference' => PaymentOrder::generateReference(),
            'amount' => '470000.00',
            'gateway' => 'manual',
            'channel' => 'bank_transfer',
            'status' => 'paid',
            'paid_at' => now()->subDay(),
        ]);

        return $order;
    }

    public function test_stok_kembali_untuk_pesanan_paid(): void
    {
        $order = $this->paidOrder('paid');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/orders/{$order->id}/refund-pending", ['reason' => 'Dibatalkan pembeli'])
            ->assertOk();

        // 7 tersisa + 3 yang dipesan
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_stok_tidak_kembali_untuk_pesanan_shipped(): void
    {
        $order = $this->paidOrder('shipped');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/orders/{$order->id}/refund-pending", ['reason' => 'Paket hilang di kurir'])
            ->assertOk();

        // Barangnya sudah keluar gudang. Menambah stok di sini berarti menjual
        // barang yang secara fisik tidak ada.
        $this->assertSame(7, $this->product->fresh()->stock);

        $this->patchJson("/api/admin/refunds/{$order->id}/refunded", ['transfer_reference' => 'REF-9'])
            ->assertOk();

        // Juga tidak kembali saat transfer selesai.
        $this->assertSame(7, $this->product->fresh()->stock);
    }

    public function test_auto_complete_melewati_pesanan_yang_menunggu_refund(): void
    {
        $order = $this->paidOrder('shipped');
        $order->forceFill(['shipped_at' => now()->subDays(30)])->save();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/orders/{$order->id}/refund-pending", ['reason' => 'Paket hilang'])
            ->assertOk();

        // Jendela konfirmasi sudah lewat jauh. Tanpa penjagaan, job ini akan
        // menutup pesanannya sebagai selesai dan mengkredit saldo seller —
        // untuk pesanan yang uangnya justru sedang dikembalikan ke pembeli.
        $this->artisan('orders:auto-complete')->assertSuccessful();

        $order->refresh();

        $this->assertSame('refund_pending', $order->status);
        $this->assertNull($order->completed_at);
        $this->assertSame(
            0,
            BalanceLog::where('transaction_id', $order->id)->where('type', 'credit')->count(),
            'Pesanan yang menunggu refund tidak boleh mengkredit saldo seller.'
        );
    }

    public function test_auto_complete_melewati_pesanan_yang_sudah_direfund(): void
    {
        $order = $this->paidOrder('shipped');
        $order->forceFill(['shipped_at' => now()->subDays(30)])->save();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/orders/{$order->id}/refund-pending", ['reason' => 'Paket hilang'])->assertOk();
        $this->patchJson("/api/admin/refunds/{$order->id}/refunded", ['transfer_reference' => 'REF-1'])->assertOk();

        $this->artisan('orders:auto-complete')->assertSuccessful();

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame(
            0,
            BalanceLog::where('transaction_id', $order->id)->where('type', 'credit')->count()
        );
    }

    public function test_alur_penuh_dari_antrean_sampai_tercatat(): void
    {
        $order = $this->paidOrder();

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/orders/{$order->id}/refund-pending", [
            'reason' => 'Paket hilang di kurir',
        ])->assertOk()->assertJsonPath('data.status', 'refund_pending');

        // Stok kembali saat keputusan diambil, bukan saat transfer selesai.
        $this->assertSame(10, $this->product->fresh()->stock);

        $this->patchJson("/api/admin/refunds/{$order->id}/refunded", [
            'transfer_reference' => 'BCA/2026/0916/001',
        ])->assertOk()->assertJsonPath('data.status', 'refunded');

        $order->refresh();

        $this->assertSame('refunded', $order->status);
        $this->assertSame('BCA/2026/0916/001', $order->refund_reference);
        $this->assertNotNull($order->refunded_at);
        $this->assertSame($this->admin->id, $order->refunded_by);
        $this->assertSame('refunded', $order->payment->status);

        // Charge ikut ditandai supaya halaman pembayaran tidak lagi bilang lunas.
        $this->assertSame(
            'refunded',
            PaymentOrder::where('checkout_group_id', $order->checkout_group_id)->value('status')
        );
    }

    public function test_saldo_seller_tidak_bertambah_tapi_tercatat_di_ledger(): void
    {
        $order = $this->paidOrder();

        $saldoAwal = app(BalanceService::class)->currentBalance($this->seller->id);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/orders/{$order->id}/refund-pending", ['reason' => 'Dibatalkan pembeli'])
            ->assertOk();
        $this->patchJson("/api/admin/refunds/{$order->id}/refunded", ['transfer_reference' => 'REF-1'])
            ->assertOk();

        $this->assertSame(
            $saldoAwal,
            app(BalanceService::class)->currentBalance($this->seller->id),
            'Refund tidak boleh menggerakkan saldo seller.'
        );

        $log = BalanceLog::where('transaction_id', $order->id)->first();

        $this->assertNotNull($log, 'Refund harus meninggalkan jejak di ledger.');
        $this->assertSame('refund', $log->type);
        $this->assertEquals(450000, (float) $log->amount);
        $this->assertStringContainsString('REF-1', $log->note);
    }

    public function test_pesanan_terkirim_boleh_direfund(): void
    {
        $order = $this->paidOrder('shipped');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/orders/{$order->id}/refund-pending", ['reason' => 'Paket ditolak penerima'])
            ->assertOk()
            ->assertJsonPath('data.status', 'refund_pending');
    }

    public function test_pesanan_selesai_tidak_bisa_direfund_lewat_jalur_ini(): void
    {
        $order = $this->paidOrder('completed');
        SellerBalance::firstOrCreate(['seller_id' => $this->seller->id], ['balance' => 0]);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/orders/{$order->id}/refund-pending", ['reason' => 'Terlambat'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_pesanan_belum_dibayar_tidak_bisa_direfund(): void
    {
        $order = $this->paidOrder('pending');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/orders/{$order->id}/refund-pending", ['reason' => 'Salah pesan'])
            ->assertStatus(422);
    }

    public function test_menandai_refunded_dua_kali_tidak_menggandakan_ledger(): void
    {
        $order = $this->paidOrder();

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/orders/{$order->id}/refund-pending", ['reason' => 'Paket hilang'])->assertOk();
        $this->patchJson("/api/admin/refunds/{$order->id}/refunded", ['transfer_reference' => 'REF-1'])->assertOk();
        $this->patchJson("/api/admin/refunds/{$order->id}/refunded", ['transfer_reference' => 'REF-2'])
            ->assertStatus(422);

        $this->assertSame(1, BalanceLog::where('transaction_id', $order->id)->count());
        $this->assertSame('REF-1', $order->fresh()->refund_reference);
    }

    public function test_bukti_transfer_wajib_diisi(): void
    {
        $order = $this->paidOrder();

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/orders/{$order->id}/refund-pending", ['reason' => 'Paket hilang'])->assertOk();

        $this->patchJson("/api/admin/refunds/{$order->id}/refunded", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('transfer_reference');
    }

    public function test_hanya_admin_yang_bisa_memproses_refund(): void
    {
        $order = $this->paidOrder();

        Sanctum::actingAs($this->seller);
        $this->postJson("/api/admin/orders/{$order->id}/refund-pending", ['reason' => 'Coba-coba'])
            ->assertStatus(403);

        Sanctum::actingAs($this->buyer);
        $this->getJson('/api/admin/refunds')->assertStatus(403);
        $this->patchJson("/api/admin/refunds/{$order->id}/refunded", ['transfer_reference' => 'X'])
            ->assertStatus(403);

        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_pembeli_melihat_status_refund_di_pesanannya(): void
    {
        $order = $this->paidOrder();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/orders/{$order->id}/refund-pending", ['reason' => 'Paket hilang di kurir'])
            ->assertOk();

        Sanctum::actingAs($this->buyer);

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'refund_pending')
            ->assertJsonPath('data.refund_reason', 'Paket hilang di kurir');
    }

    public function test_antrean_admin_menampilkan_yang_menunggu(): void
    {
        $lama = $this->paidOrder();
        $baru = $this->paidOrder();

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/orders/{$baru->id}/refund-pending", ['reason' => 'B'])->assertOk();
        $this->postJson("/api/admin/orders/{$lama->id}/refund-pending", ['reason' => 'A'])->assertOk();

        // Yang paling lama menunggu didahulukan.
        Transaction::where('id', $lama->id)->update(['refund_requested_at' => now()->subDays(3)]);

        $response = $this->getJson('/api/admin/refunds')->assertOk();

        $this->assertSame($lama->id, $response->json('data.0.id'));
        $this->assertSame(2, $response->json('meta.pending_count'));
        $this->assertEquals(900000, (float) $response->json('meta.pending_amount'));
    }
}
