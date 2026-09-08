<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class StoreController extends Controller
{
    // GET /api/seller/store
    //
    // Sellers who registered before shops existed have no row yet, so one is
    // created on first read rather than leaving them staring at an empty
    // dashboard with no way forward.
    public function show(Request $request)
    {
        if ($deny = $this->denyIfNotSeller($request)) return $deny;

        $store = $this->resolveStore($request);

        return response()->json([
            'success' => true,
            'message' => 'Shop retrieved',
            'data' => $store,
        ]);
    }

    // PUT /api/seller/store
    public function update(Request $request)
    {
        if ($deny = $this->denyIfNotSeller($request)) return $deny;

        $store = $this->resolveStore($request);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            // Multipart sends booleans as "1"/"0" strings, so accept both
            'is_open' => 'sometimes|boolean',
            'logo' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            'banner' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
        ]);

        // Uploads are attempted before anything is saved. If Cloudinary is
        // misconfigured the request fails cleanly instead of leaving the shop
        // half updated with a broken image URL.
        try {
            if ($request->hasFile('logo')) {
                $path = $request->file('logo')->store('stores', 'cloudinary');
                $validated['logo_url'] = Storage::disk('cloudinary')->url($path);
            }

            if ($request->hasFile('banner')) {
                $path = $request->file('banner')->store('stores', 'cloudinary');
                $validated['banner_url'] = Storage::disk('cloudinary')->url($path);
            }
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Image upload failed. Try again or save without the image.',
            ], 422);
        }

        unset($validated['logo'], $validated['banner']);

        $store->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Shop updated',
            'data' => $store->fresh(),
        ]);
    }

    // PUT /api/seller/store/payout
    //
    // Kept apart from the profile update so a routine edit — changing the
    // shop description, say — can never touch where the money goes.
    public function updatePayout(Request $request)
    {
        if ($deny = $this->denyIfNotSeller($request)) return $deny;

        $store = $this->resolveStore($request);

        $validated = $request->validate([
            'bank_name' => 'required|string|max:100',
            // Indonesian account numbers are digits only; length varies by bank
            'bank_account_number' => 'required|string|regex:/^[0-9]{6,20}$/',
            'bank_account_holder' => 'required|string|max:150',
        ], [
            'bank_account_number.regex' => 'Account number should be 6 to 20 digits, no spaces or dashes.',
        ]);

        $store->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Payout account updated',
            'data' => [
                'bank_name' => $store->bank_name,
                'masked_account_number' => $store->fresh()->masked_account_number,
                'account_holder' => $store->bank_account_holder,
            ],
        ]);
    }

    // PATCH /api/seller/store/status
    //
    // The open/closed switch on the dashboard. Separate endpoint so toggling
    // it doesn't require sending the whole profile back.
    public function toggleStatus(Request $request)
    {
        if ($deny = $this->denyIfNotSeller($request)) return $deny;

        $validated = $request->validate([
            'is_open' => 'required|boolean',
        ]);

        $store = $this->resolveStore($request);
        $store->update(['is_open' => $validated['is_open']]);

        return response()->json([
            'success' => true,
            'message' => $validated['is_open'] ? 'Shop is now open' : 'Shop is now closed',
            'data' => ['is_open' => $store->is_open],
        ]);
    }

    // GET /api/shops/{store}
    // Public shop page, resolved by slug via Store::getRouteKeyName().
    public function publicShow(Store $store)
    {
        return response()->json([
            'success' => true,
            'message' => 'Shop retrieved',
            'data' => [
                'name' => $store->name,
                'slug' => $store->slug,
                'description' => $store->description,
                'logo_url' => $store->logo_url,
                'banner_url' => $store->banner_url,
                'city' => $store->city,
                'province' => $store->province,
                'is_open' => $store->is_open,
            ],
        ]);
    }

    private function resolveStore(Request $request): Store
    {
        $seller = $request->user();

        return $seller->store ?? Store::create([
            'seller_id' => $seller->id,
            'name' => $seller->name . "'s Shop",
            'is_open' => true,
        ]);
    }

    private function denyIfNotSeller(Request $request)
    {
        if ($request->user()->role !== 'seller') {
            return response()->json([
                'success' => false,
                'message' => 'Only sellers have a shop.',
            ], 403);
        }

        return null;
    }
}