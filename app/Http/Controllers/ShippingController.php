<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\CartItem;
use App\Shipping\ShipmentQuote;
use App\Shipping\ShippingService;
use Illuminate\Http\Request;

class ShippingController extends Controller
{
    public function __construct(
        private readonly ShippingService $shipping,
    ) {}

    /**
     * GET /api/shipping/areas?q=tangerang
     *
     * Feeds the address form's autocomplete. Rate limited at the route rather
     * than here — a typing user generates a lot of these, and the service
     * already refuses anything under three characters.
     */
    public function areas(Request $request)
    {
        $validated = $request->validate([
            'q'     => 'required|string|min:3|max:100',
            'limit' => 'sometimes|integer|min:1|max:20',
        ]);

        $areas = $this->shipping->searchAreas(
            $validated['q'],
            $validated['limit'] ?? 10
        );

        return response()->json([
            'data' => array_map(fn ($area) => $area->toArray(), $areas),
        ]);
    }

    /**
     * POST /api/shipping/quote
     *
     * Prices a cart, one parcel per seller. Called when the buyer picks an
     * address on the checkout page, and again if they change it.
     *
     * Returns a result per seller even when some fail, because a shop that
     * never set its pickup area must not block checkout for the rest of the
     * cart. The frontend renders the reason inline on that shop's row.
     */
    public function quote(Request $request)
    {
        $validated = $request->validate([
            'address_id'      => 'required|integer',
            'cart_item_ids'   => 'required|array|min:1',
            'cart_item_ids.*' => 'integer',
        ]);

        $address = Address::where('user_id', $request->user()->id)
            ->find($validated['address_id']);

        if (!$address) {
            return response()->json(['message' => 'Address not found.'], 404);
        }

        if (!$address->destination_area_id) {
            // Saved before the area picker existed, or saved with the picker
            // skipped. Nothing to quote against, and the fix is the buyer's
            // to make — so say so plainly rather than returning empty rates.
            return response()->json([
                'message' => 'This address needs a delivery area before shipping can be calculated. Please edit it and pick your district.',
            ], 422);
        }

        $items = CartItem::with('product.seller.store')
            ->where('user_id', $request->user()->id)
            ->whereIn('id', $validated['cart_item_ids'])
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => 'No items to ship.'], 422);
        }

        // One parcel per shop: each seller ships from their own address, so a
        // cart spanning three shops is three quotes on three different routes.
        $grouped = $items->groupBy(fn (CartItem $item) => $item->product->seller_id);

        $shipments = [];
        $context = [];

        foreach ($grouped as $sellerId => $sellerItems) {
            $store = $sellerItems->first()->product->seller->store ?? null;

            $weight = $sellerItems->sum(
                fn (CartItem $item) => (int) $item->product->weight_grams * $item->quantity
            );

            $value = $sellerItems->sum(
                fn (CartItem $item) => (float) $item->product->price * $item->quantity
            );

            $shipments[$sellerId] = new ShipmentQuote(
                originAreaId: (string) ($store->origin_area_id ?? ''),
                destinationAreaId: (string) $address->destination_area_id,
                weightGrams: (int) $weight,
                itemValue: (int) round($value),
                couriers: config('shipping.couriers', []),
            );

            $context[$sellerId] = [
                'seller_id'    => $sellerId,
                'seller_name'  => $store->name ?? 'Unknown Shop',
                'weight_grams' => (int) $weight,
            ];
        }

        $results = $this->shipping->quoteForSellers($shipments);

        $payload = [];

        foreach ($results as $sellerId => $result) {
            $payload[] = $context[$sellerId] + [
                'rates' => array_map(fn ($rate) => $rate->toArray(), $result['rates']),
                'error' => $result['error'],
            ];
        }

        return response()->json([
            'data' => [
                'destination' => $address->destination_area_label,
                'shipments'   => $payload,
            ],
        ]);
    }
}