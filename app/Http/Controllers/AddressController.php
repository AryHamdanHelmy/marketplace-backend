<?php

namespace App\Http\Controllers;

use App\Models\Address;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    private const MAX_ADDRESSES = 10;

    // GET /api/addresses
    public function index(Request $request)
    {
        $addresses = Address::where('user_id', $request->user()->id)
            ->defaultFirst()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Addresses retrieved',
            'data' => $addresses,
        ]);
    }

    // POST /api/addresses
    public function store(Request $request)
    {
        $count = Address::where('user_id', $request->user()->id)->count();

        if ($count >= self::MAX_ADDRESSES) {
            return response()->json([
                'success' => false,
                'message' => 'You can save up to ' . self::MAX_ADDRESSES . ' addresses. Delete one first.',
            ], 422);
        }

        $validated = $this->validateAddress($request);

        $makeDefault = $request->boolean('is_default');
        unset($validated['is_default']);

        $address = Address::create([
            ...$validated,
            'user_id' => $request->user()->id,
        ]);

        // The model already defaults the first address, so this only matters
        // when the buyer explicitly asked for it on a later one.
        if ($makeDefault && !$address->is_default) {
            $address->makeDefault();
        }

        return response()->json([
            'success' => true,
            'message' => 'Address saved',
            'data' => $address->fresh(),
        ], 201);
    }

    // PUT /api/addresses/{id}
    public function update(Request $request, $id)
    {
        $address = $this->findOwned($request, $id);

        if (!$address) {
            return $this->notFound();
        }

        $validated = $this->validateAddress($request);
        $makeDefault = $request->boolean('is_default');
        unset($validated['is_default']);

        $address->update($validated);

        if ($makeDefault && !$address->is_default) {
            $address->makeDefault();
        }

        return response()->json([
            'success' => true,
            'message' => 'Address updated',
            'data' => $address->fresh(),
        ]);
    }

    // PATCH /api/addresses/{id}/default
    public function setDefault(Request $request, $id)
    {
        $address = $this->findOwned($request, $id);

        if (!$address) {
            return $this->notFound();
        }

        $address->makeDefault();

        return response()->json([
            'success' => true,
            'message' => 'Default address updated',
            'data' => $address->fresh(),
        ]);
    }

    // DELETE /api/addresses/{id}
    public function destroy(Request $request, $id)
    {
        $address = $this->findOwned($request, $id);

        if (!$address) {
            return $this->notFound();
        }

        // Past orders keep their own frozen copy, so removing an address never
        // affects a shipment that already happened.
        $address->delete();

        return response()->json([
            'success' => true,
            'message' => 'Address deleted',
        ]);
    }

    private function validateAddress(Request $request): array
    {
        return $request->validate([
            'label' => 'nullable|string|max:50',
            'recipient_name' => 'required|string|max:150',
            // Indonesian numbers, with or without +62 and separators
            'phone' => 'required|string|max:25|regex:/^[0-9+\-\s()]{8,25}$/',
            'street' => 'required|string|max:1000',
            'district' => 'nullable|string|max:100',
            'city' => 'required|string|max:100',
            'province' => 'required|string|max:100',
            'postal_code' => 'nullable|string|max:10',
            'courier_note' => 'nullable|string|max:255',
            'is_default' => 'sometimes|boolean',
        ], [
            'phone.regex' => 'Enter a valid phone number.',
        ]);
    }

    // Scoping every lookup by user is what stops someone reading or deleting
    // another buyer's address by guessing an id.
    private function findOwned(Request $request, $id): ?Address
    {
        return Address::where('user_id', $request->user()->id)->find($id);
    }

    private function notFound()
    {
        return response()->json([
            'success' => false,
            'message' => 'Address not found',
        ], 404);
    }
}