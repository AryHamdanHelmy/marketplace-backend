<?php

namespace App\Shipping;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Everything the app calls for shipping. Controllers talk to this, never to a
 * gateway directly.
 *
 * The gateway knows how to ask one provider one question. This knows how many
 * questions we can afford, which answers we already have, and what to do when
 * a question goes unanswered.
 *
 * On quota: the free RajaOngkir tier allows 100 calls a day. A five-seller
 * checkout is five quotes, and a buyer who changes address twice makes it
 * fifteen. Without the cache and the budget below, one afternoon of testing
 * exhausts a day.
 */
class ShippingService
{
    /**
     * Live provider calls already spent in this HTTP request.
     *
     * Reset per request because the container rebuilds each time — which is
     * exactly the scope we want. The cap protects a single checkout from
     * fanning out unboundedly; the daily quota is protected by the cache.
     */
    private int $callsMade = 0;

    public function __construct(
        private readonly ShippingGateway $gateway,
    ) {}

    /**
     * Address autocomplete.
     *
     * Cached hard — area ids are effectively static, and the same handful of
     * city names get typed over and over. A week is conservative; these
     * change on the order of years.
     */
    public function searchAreas(string $query, int $limit = 10): array
    {
        $query = trim($query);

        // Below three characters the result set is enormous and useless, and
        // it's the prefix every keystroke passes through on the way to a real
        // query — the single easiest way to burn quota on nothing.
        if (mb_strlen($query) < 3) {
            return [];
        }

        $key = 'ship:areas:' . $this->gateway->name() . ':' . md5(mb_strtolower($query)) . ':' . $limit;

        $cached = Cache::remember($key, now()->addWeek(), function () use ($query, $limit) {
            $areas = $this->gateway->searchAreas($query, $limit);

            return array_map(fn (ShippingArea $a) => $a->toArray(), $areas);
        });

        return array_map(fn (array $a) => ShippingArea::fromArray($a), $cached);
    }

    /**
     * Price one parcel, going to the provider only when the answer isn't held.
     *
     * @return ShippingRate[]
     */
    public function quote(ShipmentQuote $shipment): array
    {
        if (!$shipment->isQuotable()) {
            return [];
        }

        $key = $shipment->cacheKey($this->gateway->name());

        if ($hit = Cache::get($key)) {
            return array_map(fn (array $r) => ShippingRate::fromArray($r), $hit);
        }

        if (!$this->canSpendCall()) {
            Log::warning('Shipping quote skipped: per-request call budget spent', [
                'origin'      => $shipment->originAreaId,
                'destination' => $shipment->destinationAreaId,
            ]);

            return [];
        }

        $this->callsMade++;
        $rates = $this->gateway->quote($shipment);

        // Empty results are cached too, briefly. An unserviceable route stays
        // unserviceable, and without this every retry on a dead route costs
        // another call. Short TTL because "empty" can also mean the provider
        // was down, and that we do want to retry — just not immediately.
        $ttl = $rates
            ? now()->addHours((int) config('shipping.cache_hours', 12))
            : now()->addMinutes(15);

        Cache::put($key, array_map(fn (ShippingRate $r) => $r->toArray(), $rates), $ttl);

        return $rates;
    }

    /**
     * Price a whole checkout: one parcel per seller.
     *
     * Returns a result per seller rather than one flat list, because each shop
     * ships separately and the buyer picks a courier for each. A seller whose
     * quote fails comes back with an empty rate list and a reason — the other
     * sellers are unaffected, and checkout stays usable for the rest of the
     * cart.
     *
     * @param  array<int|string, ShipmentQuote>  $shipments  keyed by seller id
     * @return array<int|string, array{rates: ShippingRate[], error: ?string}>
     */
    public function quoteForSellers(array $shipments): array
    {
        $results = [];

        foreach ($shipments as $sellerId => $shipment) {
            if (!$shipment->isQuotable()) {
                $results[$sellerId] = [
                    'rates' => [],
                    'error' => 'This shop has not set a pickup area yet.',
                ];

                continue;
            }

            $rates = $this->quote($shipment);

            $results[$sellerId] = [
                'rates' => $rates,
                'error' => $rates ? null : 'No courier serves this route right now.',
            ];
        }

        return $results;
    }

    /**
     * Current state of one waybill.
     *
     * Called from the polling job. The short cache is a guard against the job
     * overlapping itself, not a performance measure — if a run takes longer
     * than its interval, the next run shouldn't re-ask for parcels the
     * previous one just covered.
     */
    public function track(string $courierCode, string $trackingNumber): ?TrackingResult
    {
        $key = 'ship:track:' . $this->gateway->name() . ':' . $courierCode . ':' . $trackingNumber;

        if ($hit = Cache::get($key)) {
            return TrackingResult::fromArray($hit);
        }

        $result = $this->gateway->track($courierCode, $trackingNumber);

        if (!$result) {
            return null;
        }

        Cache::put($key, $result->toArray(), now()->addMinutes(30));

        return $result;
    }

    /**
     * Whether another live call is allowed in this request.
     *
     * A cart with thirty sellers is not a reason to make thirty HTTP calls
     * inside one page load. Past the cap, quotes return empty and the buyer is
     * told to pick a courier per shop on the next step rather than waiting.
     */
    private function canSpendCall(): bool
    {
        return $this->callsMade < (int) config('shipping.max_calls_per_request', 8);
    }

    public function gatewayName(): string
    {
        return $this->gateway->name();
    }
}