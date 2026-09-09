<?php

namespace App\Payments;

/**
 * A verified statement about a charge, from a webhook or a status check.
 *
 * `status` uses Rapaku's own vocabulary — 'paid', 'expired', 'failed' — not
 * the provider's. Translating happens inside the driver so the rest of the
 * app never learns provider-specific words like 'settlement' or 'capture'.
 */
class WebhookResult
{
    public function __construct(
        public readonly string $reference,   // our PaymentOrder.reference
        public readonly string $status,      // paid | expired | failed | refunded | pending
        public readonly ?string $gatewayReference = null,
        public readonly ?string $channel = null,
        public readonly ?float $amount = null,
        public readonly array $raw = [],
    ) {}
}