<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Penjaga untuk empat celah kritikal yang ditutup di d562b78.
 *
 * Masing-masing test di sini menggambarkan serangan yang dulu berhasil.
 * Kalau ada yang hijau kembali jadi merah, celahnya terbuka lagi.
 */
class CriticalSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function buyerWithPendingOrder(): array
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $seller = User::factory()->create(['role' => 'seller']);

        $order = Transaction::create([
            'invoice_number' => 'INV-TEST-1',
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'seller_name' => $seller->name,
            'status' => 'pending',
            'total_amount' => '150000.00',
        ]);

        Payment::create([
            'transaction_id' => $order->id,
            'method' => 'bank_transfer',
            'status' => 'pending',
            'amount' => '150000.00',
        ]);

        return [$buyer, $order];
    }

    public function test_pembeli_tidak_bisa_menandai_pesanannya_sendiri_lunas(): void
    {
        [$buyer, $order] = $this->buyerWithPendingOrder();

        Sanctum::actingAs($buyer);

        $this->postJson("/api/orders/{$order->id}/pay")->assertStatus(410);

        $order->refresh();

        $this->assertSame('pending', $order->status);
        $this->assertNull($order->paid_at);
        $this->assertSame('pending', $order->payment->status);
    }

    public function test_webhook_midtrans_ditolak_saat_server_key_belum_diset(): void
    {
        config([
            'payments.default' => 'midtrans',
            'payments.midtrans.server_key' => null,
        ]);

        // Tanda tangan yang dihitung dengan key kosong — persis yang bisa
        // dibuat penyerang kalau key belum diisi saat deploy.
        $payload = [
            'order_id' => 'RPK-20260915-PALSU',
            'status_code' => '200',
            'gross_amount' => '150000.00',
            'transaction_status' => 'settlement',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'] . $payload['status_code'] . $payload['gross_amount'] . ''
        );

        $this->postJson('/api/payments/webhook/midtrans', $payload)
            ->assertStatus(400)
            ->assertJson(['message' => 'Invalid signature']);
    }

    public function test_webhook_midtrans_menolak_tanda_tangan_palsu(): void
    {
        config([
            'payments.default' => 'midtrans',
            'payments.midtrans.server_key' => 'SB-Mid-server-RAHASIA',
        ]);

        $this->postJson('/api/payments/webhook/midtrans', [
            'order_id' => 'RPK-20260915-PALSU',
            'status_code' => '200',
            'gross_amount' => '150000.00',
            'transaction_status' => 'settlement',
            'signature_key' => str_repeat('a', 128),
        ])->assertStatus(400);
    }

    public function test_grup_api_punya_throttle(): void
    {
        $this->assertContains(
            'throttle:api',
            app('router')->getMiddlewareGroups()['api'] ?? [],
            'Grup api harus lewat throttle — tanpa ini seluruh endpoint tanpa batas.'
        );

        $limit = app(\Illuminate\Cache\RateLimiter::class)
            ->limiter('api')(\Illuminate\Http\Request::create('/api/products'));

        $this->assertSame(120, $limit->maxAttempts);
    }

    public function test_seeder_admin_tidak_membuat_akun_tanpa_env(): void
    {
        putenv('ADMIN_EMAIL');
        putenv('ADMIN_PASSWORD');
        unset($_ENV['ADMIN_EMAIL'], $_ENV['ADMIN_PASSWORD']);

        $this->seed(\Database\Seeders\AdminSeeder::class);

        $this->assertSame(0, User::where('role', 'admin')->count());
    }
}
