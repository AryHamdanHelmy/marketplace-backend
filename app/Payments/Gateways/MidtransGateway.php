<?php

namespace App\Payments\Gateways;

use App\Models\PaymentOrder;
use App\Payments\ChargeResult;
use App\Payments\PaymentChannel;
use App\Payments\PaymentGateway;
use App\Payments\WebhookResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Midtrans Core API driver.
 *
 * Talks to /v2/charge and /v2/{order_id}/status directly over HTTP rather than
 * pulling in the midtrans-php SDK — the surface we need is four endpoints, and
 * one less package is one less thing to break on a Railway build.
 *
 * Our PaymentOrder.reference is sent as Midtrans's order_id. That is why the
 * reference is regenerated on every retry: Midtrans rejects a reused order_id
 * permanently, even after the first attempt expired.
 */
class MidtransGateway implements PaymentGateway
{
    private const SANDBOX_URL = 'https://api.sandbox.midtrans.com';
    private const PRODUCTION_URL = 'https://api.midtrans.com';

    public function name(): string
    {
        return 'midtrans';
    }

    public function channels(): array
    {
        return [
            new PaymentChannel(
                code: 'qris',
                label: 'QRIS',
                description: 'Scan with GoPay, OVO, DANA, ShopeePay, or any banking app.',
            ),
            new PaymentChannel(
                code: 'gopay',
                label: 'GoPay',
                description: 'Open the Gojek app and confirm.',
            ),
            new PaymentChannel(
                code: 'bca_va',
                label: 'BCA Virtual Account',
                description: 'Transfer to a virtual account number from BCA mobile or ATM.',
            ),
            new PaymentChannel(
                code: 'bni_va',
                label: 'BNI Virtual Account',
                description: 'Transfer to a virtual account number from BNI mobile or ATM.',
            ),
            new PaymentChannel(
                code: 'bri_va',
                label: 'BRI Virtual Account',
                description: 'Transfer to a virtual account number from BRI mobile or ATM.',
            ),
            new PaymentChannel(
                code: 'permata_va',
                label: 'Permata Virtual Account',
                description: 'Transfer to a virtual account number from Permata mobile or ATM.',
            ),
        ];
    }

    public function createCharge(PaymentOrder $order, string $channel): ChargeResult
    {
        if (!$this->serverKey()) {
            return ChargeResult::failed('Midtrans is not configured yet.');
        }

        $payload = $this->chargePayload($order, $channel);

        if ($payload === null) {
            return ChargeResult::failed("This payment method isn't available right now.");
        }

        try {
            $response = $this->request()->post('/v2/charge', $payload);
        } catch (\Throwable $e) {
            Log::error('Midtrans charge request failed', [
                'reference' => $order->reference,
                'message' => $e->getMessage(),
            ]);

            return ChargeResult::failed('Could not reach the payment provider. Please try again.');
        }

        $body = $response->json() ?? [];

        // Midtrans answers 200/201 on success and puts its own code in
        // status_code, so both have to be checked.
        $statusCode = (string) ($body['status_code'] ?? '');

        if (!$response->successful() || !in_array($statusCode, ['200', '201'], true)) {
            Log::error('Midtrans rejected a charge', [
                'reference' => $order->reference,
                'status_code' => $statusCode,
                'status_message' => $body['status_message'] ?? null,
            ]);

            // Midtrans's own message leaks merchant config details, so the
            // buyer gets something generic and the detail goes to the log.
            return ChargeResult::failed(
                'Could not start the payment. Please pick another method.',
                $body
            );
        }

        return new ChargeResult(
            success: true,
            gatewayReference: $body['transaction_id'] ?? null,
            instructions: $this->instructionsFrom($channel, $body),
            expiresAt: $this->expiryFrom($body),
            raw: $body,
        );
    }

    public function parseWebhook(array $payload, array $headers): ?WebhookResult
    {
        // Tanpa server key, hash di bawah dihitung terhadap string kosong —
        // dan string kosong adalah sesuatu yang bisa ditebak siapa pun. Satu
        // env var yang lupa diisi saat deploy akan mengubah pemeriksaan tanda
        // tangan jadi formalitas yang bisa dipalsukan dari luar. Lebih baik
        // menolak semua notifikasi daripada menerima yang palsu.
        if (!$this->serverKey()) {
            Log::error('Midtrans webhook ditolak: MIDTRANS_SERVER_KEY belum diset.');

            return null;
        }

        $orderId = $payload['order_id'] ?? null;
        $statusCode = $payload['status_code'] ?? null;
        $grossAmount = $payload['gross_amount'] ?? null;
        $signature = $payload['signature_key'] ?? null;

        if (!$orderId || !$statusCode || $grossAmount === null || !$signature) {
            return null;
        }

        // Midtrans signs with SHA-512 over order_id + status_code +
        // gross_amount + server key. gross_amount must be used exactly as it
        // arrived ("275000.00"), not reformatted, or the hash won't match.
        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $this->serverKey());

        if (!hash_equals($expected, (string) $signature)) {
            Log::warning('Midtrans webhook failed signature check', ['order_id' => $orderId]);

            return null;
        }

        return $this->toResult($payload);
    }

    public function fetchStatus(PaymentOrder $order): ?WebhookResult
    {
        if (!$this->serverKey()) {
            return null;
        }

        try {
            $response = $this->request()->get('/v2/' . $order->reference . '/status');
        } catch (\Throwable $e) {
            Log::error('Midtrans status request failed', [
                'reference' => $order->reference,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $body = $response->json() ?? [];

        // 404 means Midtrans never saw this charge — nothing to apply, and
        // certainly not a reason to mark the order failed.
        if (!$response->successful()) {
            return null;
        }

        return $this->toResult($body);
    }

    /**
     * Build the channel-specific part of the charge request.
     *
     * Returns null for a channel this driver doesn't offer, rather than
     * sending Midtrans something it will reject.
     */
    private function chargePayload(PaymentOrder $order, string $channel): ?array
    {
        $base = [
            'transaction_details' => [
                'order_id' => $order->reference,
                // IDR has no minor units at Midtrans — a decimal here is
                // rejected outright.
                'gross_amount' => (int) round((float) $order->amount),
            ],
            'custom_expiry' => [
                'expiry_duration' => (int) config('payments.expiry_hours', 24) * 60,
                'unit' => 'minute',
            ],
        ];

        if ($buyer = $order->buyer) {
            // No phone column on users — Midtrans treats customer_details as
            // optional, and a partial block is better than none for its fraud
            // scoring.
            $base['customer_details'] = array_filter([
                'first_name' => $buyer->name,
                'email' => $buyer->email,
            ]);
        }

        return match ($channel) {
            'qris' => $base + [
                'payment_type' => 'qris',
                'qris' => ['acquirer' => 'gopay'],
            ],
            'gopay' => $base + [
                'payment_type' => 'gopay',
                'gopay' => ['enable_callback' => false],
            ],
            'bca_va', 'bni_va', 'bri_va' => $base + [
                'payment_type' => 'bank_transfer',
                'bank_transfer' => ['bank' => str_replace('_va', '', $channel)],
            ],
            'permata_va' => $base + [
                'payment_type' => 'permata',
            ],
            default => null,
        };
    }

    /**
     * Reshape the charge response into what the payment page needs.
     *
     * The frontend switches on `type`, so each shape stays flat and boring.
     */
    private function instructionsFrom(string $channel, array $body): array
    {
        $actions = collect($body['actions'] ?? []);
        $action = fn (string $name) => $actions->firstWhere('name', $name)['url'] ?? null;

        if ($channel === 'qris') {
            return array_filter([
                'type' => 'qris',
                'qr_string' => $body['qr_string'] ?? null,
                'qr_url' => $action('generate-qr-code'),
                'acquirer' => $body['acquirer'] ?? null,
            ]);
        }

        if ($channel === 'gopay') {
            return array_filter([
                'type' => 'gopay',
                'deeplink_url' => $action('deeplink-redirect'),
                'qr_url' => $action('generate-qr-code'),
            ]);
        }

        // Permata returns a bare permata_va_number; every other bank comes
        // back inside va_numbers[].
        $vaNumber = $body['permata_va_number']
            ?? ($body['va_numbers'][0]['va_number'] ?? null);

        $bank = $body['va_numbers'][0]['bank']
            ?? ($channel === 'permata_va' ? 'permata' : str_replace('_va', '', $channel));

        return array_filter([
            'type' => 'virtual_account',
            'bank' => strtoupper($bank),
            'va_number' => $vaNumber,
            'note' => 'Transfer the exact amount before the deadline.',
        ]);
    }

    private function expiryFrom(array $body): Carbon
    {
        // Midtrans echoes its own window back. Trust that over our config,
        // because it is the one actually enforced.
        if (!empty($body['expiry_time'])) {
            try {
                return Carbon::parse($body['expiry_time'], 'Asia/Jakarta');
            } catch (\Throwable) {
                // fall through
            }
        }

        return Carbon::now()->addHours((int) config('payments.expiry_hours', 24));
    }

    /**
     * Translate a verified Midtrans payload into Rapaku's vocabulary.
     */
    private function toResult(array $payload): WebhookResult
    {
        return new WebhookResult(
            reference: (string) ($payload['order_id'] ?? ''),
            status: $this->mapStatus($payload),
            gatewayReference: $payload['transaction_id'] ?? null,
            channel: $payload['payment_type'] ?? null,
            amount: isset($payload['gross_amount']) ? (float) $payload['gross_amount'] : null,
            raw: $payload,
        );
    }

    private function mapStatus(array $payload): string
    {
        $status = $payload['transaction_status'] ?? '';
        $fraud = $payload['fraud_status'] ?? null;

        return match ($status) {
            'settlement' => 'paid',

            // 'capture' is card-only and can still be reversed while fraud
            // review is open, so only an accepted capture counts as money in.
            'capture' => $fraud === 'accept' ? 'paid' : 'pending',

            'pending' => 'pending',
            'expire' => 'expired',
            'deny', 'cancel', 'failure' => 'failed',
            'refund', 'partial_refund' => 'refunded',
            default => 'pending',
        };
    }

    private function request()
    {
        return Http::baseUrl($this->baseUrl())
            ->withBasicAuth($this->serverKey(), '')
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->retry(2, 500, throw: false);
    }

    private function baseUrl(): string
    {
        return config('payments.midtrans.is_production')
            ? self::PRODUCTION_URL
            : self::SANDBOX_URL;
    }

    private function serverKey(): ?string
    {
        return config('payments.midtrans.server_key');
    }
}