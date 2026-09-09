<?php

namespace App\Payments;

use App\Models\PaymentOrder;

/**
 * The seam between Rapaku and whichever payment provider is in use.
 *
 * Everything above this interface — checkout, orders, the balance ledger —
 * knows only about PaymentOrder and the three shapes below. Adding Midtrans
 * or Xendit later means writing one class that implements this, and changing
 * a single line in config/payments.php. Nothing else moves.
 */
interface PaymentGateway
{
    /**
     * Short identifier stored on each PaymentOrder row: 'manual', 'midtrans'.
     */
    public function name(): string;

    /**
     * Channels this gateway can offer, for the payment method picker.
     *
     * @return PaymentChannel[]
     */
    public function channels(): array;

    /**
     * Ask the provider to open a charge.
     *
     * Implementations must not mutate the PaymentOrder — the caller writes
     * the result. That keeps the database transaction boundary in one place
     * rather than scattered through driver code.
     */
    public function createCharge(PaymentOrder $order, string $channel): ChargeResult;

    /**
     * Turn an incoming webhook into a decision.
     *
     * Verifying the signature belongs here, inside the driver, because every
     * provider signs differently. Returning null means the payload was not
     * genuine and must be ignored — never trust an unverified webhook, since
     * anyone can post to that URL.
     */
    public function parseWebhook(array $payload, array $headers): ?WebhookResult;

    /**
     * Ask the provider for the current state of a charge.
     *
     * Webhooks get lost. A buyer staring at a "waiting" screen should be able
     * to trigger this and have the truth fetched directly.
     */
    public function fetchStatus(PaymentOrder $order): ?WebhookResult;
}