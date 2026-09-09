<?php

namespace App\Payments;

use DateTimeInterface;

/**
 * What came back when a charge was opened.
 *
 * `instructions` is whatever the buyer has to act on — a QR string, a virtual
 * account number, a redirect URL. Its shape is the driver's business; the
 * frontend switches on the channel to decide how to render it.
 */
class ChargeResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $gatewayReference = null,
        public readonly array $instructions = [],
        public readonly ?DateTimeInterface $expiresAt = null,
        public readonly array $raw = [],
        public readonly ?string $errorMessage = null,
    ) {}

    public static function failed(string $message, array $raw = []): self
    {
        return new self(success: false, raw: $raw, errorMessage: $message);
    }
}