<?php

namespace App\Shipping;

/**
 * One courier service the buyer can pick, already priced.
 *
 * What the checkout page renders as a row, and what gets frozen onto the
 * transaction once chosen.
 */
class ShippingRate
{
    public function __construct(
        /** Courier code as the provider spells it: "jne", "sicepat", "jnt". */
        public readonly string $courierCode,

        /** Display name: "JNE", "SiCepat". */
        public readonly string $courierName,

        /** Service code: "REG", "YES", "BEST". */
        public readonly string $serviceCode,

        /** What the buyer reads: "Reguler", "Next Day". */
        public readonly string $serviceName,

        /** Rupiah, whole numbers. Couriers don't price in cents. */
        public readonly int $cost,

        /**
         * Estimated days, as free text: "2-3", "1", occasionally "1-2 HARI".
         * Not parsed into numbers — providers are inconsistent enough that
         * every parser eventually meets a string it mangles, and a wrong
         * number reads worse than the courier's own words.
         */
        public readonly ?string $etd = null,
    ) {}

    /**
     * Stable identifier the frontend sends back when the buyer picks this one.
     *
     * Courier plus service, because "jne" alone is ambiguous — REG and YES are
     * different prices on the same route.
     */
    public function key(): string
    {
        return $this->courierCode . ':' . $this->serviceCode;
    }

    public function toArray(): array
    {
        return [
            'key'           => $this->key(),
            'courier_code'  => $this->courierCode,
            'courier_name'  => $this->courierName,
            'service_code'  => $this->serviceCode,
            'service_name'  => $this->serviceName,
            'cost'          => $this->cost,
            'etd'           => $this->etd,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            courierCode: $data['courier_code'],
            courierName: $data['courier_name'],
            serviceCode: $data['service_code'],
            serviceName: $data['service_name'],
            cost: (int) $data['cost'],
            etd: $data['etd'] ?? null,
        );
    }
}