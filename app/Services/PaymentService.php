<?php

namespace App\Services;

use App\Models\PaymentOrder;
use App\Models\Transaction;
use App\Payments\PaymentGateway;
use App\Payments\WebhookResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Everything the application does with money-in, in one place.
 *
 * Controllers talk to this. This talks to whichever PaymentGateway driver is
 * configured. Nothing above knows the provider's name.
 */
class PaymentService
{
    public function gateway(?string $name = null): PaymentGateway
    {
        $name = $name ?: config('payments.default');
        $class = config("payments.gateways.{$name}");

        if (!$class || !class_exists($class)) {
            throw new RuntimeException("Payment gateway [{$name}] is not configured.");
        }

        return app($class);
    }

    public function availableChannels(): array
    {
        return array_map(
            fn ($channel) => $channel->toArray(),
            $this->gateway()->channels()
        );
    }

    /**
     * Open a charge covering every order in a checkout group.
     *
     * Reuses an existing charge when one is still payable, so a buyer who
     * refreshes the payment page doesn't generate a second bill for the same
     * cart.
     */
    public function createCharge(string $checkoutGroupId, int $buyerId, string $channel): PaymentOrder
    {
        $existing = PaymentOrder::where('checkout_group_id', $checkoutGroupId)
            ->where('buyer_id', $buyerId)
            ->first();

        if ($existing && $existing->isSettled()) {
            throw new RuntimeException('This order has already been paid.');
        }

        if ($existing && $existing->isPayable() && $existing->channel === $channel) {
            return $existing;
        }

        $orders = Transaction::where('checkout_group_id', $checkoutGroupId)
            ->where('buyer_id', $buyerId)
            ->get();

        if ($orders->isEmpty()) {
            throw new RuntimeException('Checkout not found.');
        }

        if ($orders->contains(fn ($o) => $o->status !== 'pending')) {
            throw new RuntimeException('These orders are no longer awaiting payment.');
        }

        $amount = (float) $orders->sum('total_amount');
        $gateway = $this->gateway();

        // The row is written first so a charge can never exist at the provider
        // without a local record of it. An orphaned charge is far worse than
        // an unused row.
        $order = DB::transaction(function () use ($existing, $checkoutGroupId, $buyerId, $amount, $gateway, $channel) {
            if ($existing) {
                $existing->update([
                    // A fresh reference: gateways reject a reused order id
                    // permanently, even after the first attempt expired.
                    'reference' => PaymentOrder::generateReference(),
                    'amount' => $amount,
                    'gateway' => $gateway->name(),
                    'channel' => $channel,
                    'status' => 'pending',
                    'gateway_reference' => null,
                    'instructions' => null,
                    'expires_at' => null,
                ]);

                return $existing->fresh();
            }

            return PaymentOrder::create([
                'checkout_group_id' => $checkoutGroupId,
                'buyer_id' => $buyerId,
                'reference' => PaymentOrder::generateReference(),
                'amount' => $amount,
                'gateway' => $gateway->name(),
                'channel' => $channel,
                'status' => 'pending',
            ]);
        });

        $result = $gateway->createCharge($order, $channel);

        if (!$result->success) {
            $order->update([
                'status' => 'failed',
                'payload' => $result->raw,
            ]);

            throw new RuntimeException($result->errorMessage ?? 'Could not start the payment.');
        }

        $order->update([
            'status' => 'awaiting_payment',
            'gateway_reference' => $result->gatewayReference,
            'instructions' => $result->instructions,
            'expires_at' => $result->expiresAt,
            'payload' => $result->raw,
        ]);

        return $order->fresh();
    }

    /**
     * Apply a verified result from a webhook or a status check.
     *
     * Providers retry webhooks until they get a 200, and sometimes deliver the
     * same event twice regardless. The row is locked and re-checked so a
     * repeated 'paid' can't settle the same orders twice.
     */
    public function applyResult(WebhookResult $result): ?PaymentOrder
    {
        return DB::transaction(function () use ($result) {
            $order = PaymentOrder::where('reference', $result->reference)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                Log::warning('Payment result for unknown reference', [
                    'reference' => $result->reference,
                ]);
                return null;
            }

            if ($order->isSettled()) {
                return $order;
            }

            if ($result->status === 'paid') {
                // Guard against an underpayment being treated as settled.
                if ($result->amount !== null && $result->amount + 0.01 < (float) $order->amount) {
                    Log::error('Payment amount below the charge', [
                        'reference' => $order->reference,
                        'expected' => $order->amount,
                        'received' => $result->amount,
                    ]);

                    $order->update([
                        'status' => 'failed',
                        'payload' => $result->raw,
                    ]);

                    return $order;
                }

                $order->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'gateway_reference' => $result->gatewayReference ?? $order->gateway_reference,
                    'payload' => $result->raw,
                ]);

                $this->settleOrders($order);

                return $order;
            }

            if (in_array($result->status, ['expired', 'failed', 'refunded'])) {
                $order->update([
                    'status' => $result->status,
                    'payload' => $result->raw,
                ]);
            }

            return $order;
        });
    }

    /**
     * Mark every seller order in the group as paid.
     *
     * Runs inside applyResult's transaction, so the charge and the orders
     * can never disagree about whether money arrived.
     */
    private function settleOrders(PaymentOrder $order): void
    {
        $transactions = Transaction::where('checkout_group_id', $order->checkout_group_id)
            ->lockForUpdate()
            ->get();

        foreach ($transactions as $transaction) {
            if ($transaction->status !== 'pending') {
                continue;
            }

            $transaction->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            // Keeps the existing per-transaction payment row in step, so
            // OrderController and MyOrders carry on working unchanged.
            $transaction->payment?->update([
                'status' => 'verified',
                'paid_at' => now(),
            ]);
        }
    }

    /**
     * Ask the provider directly. Webhooks get lost; a buyer stuck on the
     * waiting screen should be able to force the truth.
     */
    public function refreshStatus(PaymentOrder $order): ?PaymentOrder
    {
        $result = $this->gateway($order->gateway)->fetchStatus($order);

        if (!$result) {
            return $order;
        }

        return $this->applyResult($result);
    }
}