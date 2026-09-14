<?php

namespace App\Shipping;

use Illuminate\Support\Carbon;

/**
 * A waybill's state as the courier last reported it.
 *
 * Stored on transactions.tracking_snapshot and read from there by the order
 * page. Nothing in the request path calls the courier for this.
 */
class TrackingResult
{
    /**
     * Rapaku's own status vocabulary.
     *
     * Couriers each invent their own — "MANIFESTED", "ON PROCESS", "DELIVERED
     * TO [name]", "Terkirim" — and several change the wording between
     * services. Drivers map into these five so the UI has a fixed set to
     * render, and so a courier renaming a status doesn't break a badge.
     */
    public const PENDING   = 'pending';    // courier has the number, no scan yet
    public const PICKED_UP = 'picked_up';
    public const IN_TRANSIT = 'in_transit';
    public const DELIVERED = 'delivered';
    public const PROBLEM   = 'problem';    // returned, lost, refused, held

    public function __construct(
        public readonly string $trackingNumber,
        public readonly string $courierCode,

        /** One of the constants above. */
        public readonly string $status,

        /** The courier's own wording for the latest scan, kept verbatim. */
        public readonly ?string $statusText = null,

        public readonly ?Carbon $lastUpdatedAt = null,

        /** Who signed for it, when delivered. */
        public readonly ?string $receivedBy = null,

        /**
         * Scan history, newest first. Each entry: date, description, location.
         * Shape stays loose on purpose — this is rendered as a list, never
         * queried, and pinning it down would mean rewriting every driver the
         * first time a courier adds a field.
         */
        public readonly array $history = [],
    ) {}

    /**
     * Whether the courier is done with this parcel.
     *
     * The polling job uses this to stop asking. Without it, every delivered
     * order keeps consuming quota forever — and delivered orders only
     * accumulate.
     */
    public function isFinal(): bool
    {
        return in_array($this->status, [self::DELIVERED, self::PROBLEM], true);
    }

    public function toArray(): array
    {
        return [
            'tracking_number' => $this->trackingNumber,
            'courier_code'    => $this->courierCode,
            'status'          => $this->status,
            'status_text'     => $this->statusText,
            'last_updated_at' => $this->lastUpdatedAt?->toIso8601String(),
            'received_by'     => $this->receivedBy,
            'history'         => $this->history,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            trackingNumber: $data['tracking_number'],
            courierCode: $data['courier_code'],
            status: $data['status'],
            statusText: $data['status_text'] ?? null,
            lastUpdatedAt: isset($data['last_updated_at'])
                ? Carbon::parse($data['last_updated_at'])
                : null,
            receivedBy: $data['received_by'] ?? null,
            history: $data['history'] ?? [],
        );
    }
}