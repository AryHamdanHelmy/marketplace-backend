<?php

namespace App\Shipping;

/**
 * The seam between Rapaku and whichever courier aggregator is in use.
 *
 * Mirrors App\Payments\PaymentGateway on purpose: one interface, a driver per
 * provider, resolved from config. Swapping RajaOngkir for KiriminAja later
 * should touch this folder and nothing else.
 *
 * Three rules hold for every implementation:
 *
 * 1. Drivers do NOT cache. Caching lives in ShippingService, so a swap of
 *    provider can't quietly lose it, and one cache policy covers all of them.
 *
 * 2. Drivers never throw for an expected failure — a quota ceiling, an
 *    unserviceable route, a courier that's down. Those return an empty result
 *    or null. Checkout has to stay usable when the aggregator isn't, and a
 *    thrown exception three layers down turns a degraded quote into a 500.
 *
 * 3. Every method here is a network call. Assume it can take seconds, assume
 *    it can fail, and never put one inside a loop over cart items — see the
 *    note on quote() below.
 */
interface ShippingGateway
{
    /**
     * Identifier used in config and stored on transactions.
     */
    public function name(): string;

    /**
     * Search courier areas by free text ("Tangerang Selatan", "15310").
     *
     * Backs the address form's autocomplete. Every provider requires their own
     * area id rather than a typed city name, so this is the only way a buyer's
     * address becomes quotable.
     *
     * Results are near-static — an area id doesn't change month to month —
     * which makes this the cheapest thing to cache hard and the most wasteful
     * thing to call live on every keystroke. The frontend debounces; the
     * service caches for days.
     *
     * @return ShippingArea[] Empty when nothing matches, or when the provider
     *                        is unreachable. The caller can't tell the two
     *                        apart, and shouldn't need to.
     */
    public function searchAreas(string $query, int $limit = 10): array;

    /**
     * Price one parcel: one origin, one destination, one weight.
     *
     * Deliberately NOT batched, because no provider offers a real batch
     * endpoint — a "batch" here would just be a loop wearing a disguise, and
     * hiding it behind a plural method name is how a five-seller cart
     * quietly becomes five serial HTTP calls inside a request.
     *
     * ShippingService is what fans out across sellers, and it is where the
     * cache, the per-request budget and the partial-failure handling belong.
     * Calling this directly from a controller is a mistake.
     *
     * @return ShippingRate[] One entry per courier service available on the
     *                        route, cheapest first. Empty means the route
     *                        isn't served, the weight is out of range, or the
     *                        provider failed — all of which look the same to
     *                        a buyer: no options, pick another courier.
     */
    public function quote(ShipmentQuote $shipment): array;

    /**
     * Fetch the courier's history for one waybill.
     *
     * Called from a queued job on a schedule, not from the order page. A buyer
     * refreshing their orders must never trigger a courier call: it's the
     * fastest way to burn a daily quota, and it makes page load depend on a
     * third party. The page reads transactions.tracking_snapshot instead.
     *
     * @return TrackingResult|null Null when the waybill is unknown to the
     *                             courier yet — normal for the first hours
     *                             after a seller inputs it — or on failure.
     *                             Neither case should overwrite a snapshot
     *                             that already exists.
     */
    public function track(string $courierCode, string $trackingNumber): ?TrackingResult;
}