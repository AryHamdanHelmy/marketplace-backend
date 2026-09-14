<?php

namespace App\Shipping\Gateways;

use App\Shipping\ShipmentQuote;
use App\Shipping\ShippingGateway;
use App\Shipping\TrackingResult;

/**
 * A gateway that knows nothing.
 *
 * Returns no areas, no rates and no tracking — the same shape checkout already
 * has to handle when a provider is down or a route is unserved. That makes it
 * useful rather than merely inert:
 *
 *   - Local and CI runs need no API key and spend no quota.
 *   - The degraded path gets exercised constantly instead of only during an
 *     outage, which is when nobody wants to discover it was never tested.
 *
 * If the checkout page looks broken with this driver active, it will look
 * broken in production the first time RajaOngkir has a bad afternoon.
 */
class NullShippingGateway implements ShippingGateway
{
    public function name(): string
    {
        return 'null';
    }

    public function searchAreas(string $query, int $limit = 10): array
    {
        return [];
    }

    public function quote(ShipmentQuote $shipment): array
    {
        return [];
    }

    public function track(string $courierCode, string $trackingNumber): ?TrackingResult
    {
        return null;
    }
}