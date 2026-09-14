<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active gateway
    |--------------------------------------------------------------------------
    |
    | Which driver from the registry below handles quotes and tracking. Kept
    | in env so staging can point at a sandbox key, or at 'null' to develop
    | checkout without spending a live quota at all.
    |
    */

    'default' => env('SHIPPING_GATEWAY', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | The 'null' driver returns no rates and no tracking. That is a usable
    | state, not a broken one: checkout already has to survive a provider
    | outage, so the same path covers "not configured yet".
    |
    */

    'gateways' => [
        'null' => \App\Shipping\Gateways\NullShippingGateway::class,
        'rajaongkir' => \App\Shipping\Gateways\RajaOngkirGateway::class,
        // 'kiriminaja' => \App\Shipping\Gateways\KiriminAjaGateway::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Quota guards
    |--------------------------------------------------------------------------
    |
    | RajaOngkir's free Starter tier allows 100 API calls a day. These two
    | numbers are what stand between that ceiling and an afternoon of testing.
    |
    | max_calls_per_request caps the fan-out of a single multi-seller
    | checkout. Eight covers almost every real cart; past it, quotes return
    | empty rather than making the buyer wait on a queue of HTTP calls.
    |
    | cache_hours is how long a quote for one route and weight stays good.
    | Courier tariffs change on the order of months, so twelve hours is
    | cautious. Raising it is the cheapest way to buy quota headroom.
    |
    */

    'max_calls_per_request' => (int) env('SHIPPING_MAX_CALLS_PER_REQUEST', 8),
    'cache_hours'           => (int) env('SHIPPING_CACHE_HOURS', 12),

    /*
    |--------------------------------------------------------------------------
    | Couriers offered
    |--------------------------------------------------------------------------
    |
    | Empty means ask the provider for everything it serves. Narrowing this
    | list makes responses smaller and the checkout page shorter, but does not
    | save a call — it is one request either way.
    |
    */

    'couriers' => array_filter(
        explode(',', (string) env('SHIPPING_COURIERS', 'jne,jnt,sicepat'))
    ),

    /*
    |--------------------------------------------------------------------------
    | Tracking
    |--------------------------------------------------------------------------
    |
    | poll_interval_hours is how often an in-flight parcel is re-checked.
    | Couriers scan a few times a day, so hourly polling spends quota to learn
    | nothing. Parcels that reached a final state are never polled again.
    |
    | poll_batch_size caps how many parcels one scheduled run will look at.
    | Without it, a backlog after an outage would try to drain in a single
    | run and exhaust the daily quota in one go.
    |
    | stale_after_days stops chasing waybills the courier has gone quiet on.
    | A number that never scans is usually a typo, and it would otherwise be
    | polled forever.
    |
    */

    'poll_interval_hours' => (int) env('SHIPPING_POLL_INTERVAL_HOURS', 6),
    'poll_batch_size'     => (int) env('SHIPPING_POLL_BATCH_SIZE', 25),
    'stale_after_days'    => (int) env('SHIPPING_STALE_AFTER_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | RajaOngkir
    |--------------------------------------------------------------------------
    */

    'rajaongkir' => [
        'api_key'  => env('RAJAONGKIR_API_KEY'),
        'base_url' => env('RAJAONGKIR_BASE_URL', 'https://rajaongkir.komerce.id/api/v1'),
    ],

];