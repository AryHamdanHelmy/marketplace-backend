<?php

namespace App\Shipping;

/**
 * One searchable courier area, returned by address autocomplete.
 *
 * The id is what every later call needs; the rest exists so a buyer can tell
 * two identically named kecamatan apart — and Indonesia has plenty.
 */
class ShippingArea
{
    public function __construct(
        /**
         * Provider's own area id. String, not int: providers disagree on the
         * type, and a leading zero lost to a cast is a silent wrong-city bug.
         */
        public readonly string $id,

        /**
         * Full readable path, province last:
         * "Cibodas, Tangerang, Banten".
         *
         * Stored alongside the id on addresses and stores so a settings page
         * can show where a shop ships from without calling the provider.
         */
        public readonly string $label,

        public readonly ?string $district = null,
        public readonly ?string $city = null,
        public readonly ?string $province = null,
        public readonly ?string $postalCode = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'label'       => $this->label,
            'district'    => $this->district,
            'city'        => $this->city,
            'province'    => $this->province,
            'postal_code' => $this->postalCode,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            label: $data['label'],
            district: $data['district'] ?? null,
            city: $data['city'] ?? null,
            province: $data['province'] ?? null,
            postalCode: $data['postal_code'] ?? null,
        );
    }
}