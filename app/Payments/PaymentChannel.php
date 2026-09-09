<?php

namespace App\Payments;

/**
 * One payment method a gateway can offer, for the checkout picker.
 */
class PaymentChannel
{
    public function __construct(
        public readonly string $code,        // 'qris', 'bca_va', 'gopay'
        public readonly string $label,       // 'QRIS'
        public readonly ?string $description = null,
        public readonly bool $instant = true,
    ) {}

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'description' => $this->description,
            'instant' => $this->instant,
        ];
    }
}