<?php

namespace App\Providers;

use App\Shipping\Gateways\NullShippingGateway;
use App\Shipping\ShippingGateway;
use App\Shipping\ShippingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Resolves the configured shipping driver.
 *
 * Mirrors how payments are wired, with one deliberate difference: a bad
 * shipping config degrades instead of throwing. Payments failing loudly is
 * correct — nobody should be able to check out through a misconfigured
 * gateway. Shipping failing loudly would take the whole site down over a
 * typo in an env var, when the honest answer is just "no couriers available".
 */
class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShippingGateway::class, function () {
            $name = config('shipping.default', 'null');
            $class = config("shipping.gateways.{$name}");

            if (!$class || !class_exists($class)) {
                Log::error('Unknown shipping gateway configured, falling back', [
                    'configured' => $name,
                ]);

                return new NullShippingGateway();
            }

            return $this->app->make($class);
        });

        // Singleton, not a fresh instance per resolution — the per-request
        // call budget lives on ShippingService, and a new instance for every
        // injection point would hand each one its own budget, which is the
        // same as having none.
        $this->app->singleton(ShippingService::class);
    }
}