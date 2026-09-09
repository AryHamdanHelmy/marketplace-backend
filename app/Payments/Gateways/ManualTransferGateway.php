<?php

namespace App\Payments\Gateways;

use App\Models\PaymentOrder;
use App\Payments\ChargeResult;
use App\Payments\PaymentChannel;
use App\Payments\PaymentGateway;
use App\Payments\WebhookResult;
use Illuminate\Support\Carbon;

/**
 * The driver used while no payment provider is connected.
 *
 * It issues bank transfer instructions and waits for someone to confirm the
 * money arrived — which is how Rapaku already works today. Nothing here calls
 * out to the internet.
 *
 * It exists for two reasons. Checkout keeps working with no provider account,
 * and the shape of a real driver becomes obvious: a Midtrans implementation
 * replaces the bodies of these five methods and nothing above this class
 * changes.
 */
class ManualTransferGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function channels(): array
    {
        return [
            new PaymentChannel(
                code: 'bank_transfer',
                label: 'Bank transfer',
                description: 'Transfer manually, then confirm your payment.',
                instant: false,
            ),
        ];
    }

    public function createCharge(PaymentOrder $order, string $channel): ChargeResult
    {
        if ($channel !== 'bank_transfer') {
            return ChargeResult::failed("This channel isn't available right now.");
        }

        $account = config('payments.manual');

        if (empty($account['account_number'])) {
            return ChargeResult::failed('No payment account is configured yet.');
        }

        // The reference goes in the transfer description. Without it there is
        // no way to tell whose money arrived, since bank statements show only
        // the sender's name.
        return new ChargeResult(
            success: true,
            gatewayReference: $order->reference,
            instructions: [
                'type' => 'bank_transfer',
                'bank_name' => $account['bank_name'],
                'account_number' => $account['account_number'],
                'account_holder' => $account['account_holder'],
                'amount' => (float) $order->amount,
                'note' => 'Put ' . $order->reference . ' in the transfer description.',
            ],
            expiresAt: Carbon::now()->addHours(
                (int) config('payments.expiry_hours', 24)
            ),
        );
    }

    /**
     * There is no provider to call back, so nothing posted here is genuine.
     * Confirmation happens through the admin panel instead.
     */
    public function parseWebhook(array $payload, array $headers): ?WebhookResult
    {
        return null;
    }

    /**
     * No provider to ask either. The stored status is already the truth.
     */
    public function fetchStatus(PaymentOrder $order): ?WebhookResult
    {
        return new WebhookResult(
            reference: $order->reference,
            status: $order->status === 'paid' ? 'paid' : 'pending',
            gatewayReference: $order->gateway_reference,
            channel: $order->channel,
            amount: (float) $order->amount,
        );
    }
}