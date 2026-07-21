<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use Illuminate\Http\Request;

class CartController extends Controller
{
    // GET /api/cart
    public function index(Request $request)
    {
        $items = CartItem::with(['product.primaryImage', 'product.seller'])
            ->where('user_id', $request->user()->id)
            ->get();

        $formatted = $items->map(fn($item) => [
            'id' => $item->id,
            'quantity' => $item->quantity,
            'product' => [
                'id' => $item->product->id,
                'title' => $item->product->name,
                'price' => $item->product->price,
                // image_path sekarang selalu berisi URL lengkap dari Cloudinary
                'thumbnail' => $item->product->primaryImage?->image_path,
                'seller' => $item->product->seller?->name,
            ],
            'subtotal' => $item->quantity * $item->product->price,
        ]);

        return response()->json([
            'success' => true,
            'data' => $formatted,
            'total' => $formatted->sum('subtotal'),
        ]);
    }

    // POST /api/cart  { product_id, quantity? }
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'nullable|integer|min:1',
        ]);

        $item = CartItem::where('user_id', $request->user()->id)
            ->where('product_id', $validated['product_id'])
            ->first();

        if ($item) {
            // Udah ada di cart -> tambah quantity
            $item->quantity += $validated['quantity'] ?? 1;
            $item->save();
        } else {
            $item = CartItem::create([
                'user_id' => $request->user()->id,
                'product_id' => $validated['product_id'],
                'quantity' => $validated['quantity'] ?? 1,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product added to cart',
            'data' => $item,
        ], 201);
    }

    // PUT /api/cart/{id}  { quantity }
    public function update(Request $request, $id)
    {
        $item = CartItem::where('user_id', $request->user()->id)->find($id);

        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Item not found'], 404);
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $item->quantity = $validated['quantity'];
        $item->save();

        return response()->json(['success' => true, 'data' => $item]);
    }

    // DELETE /api/cart/{id}
    public function destroy(Request $request, $id)
    {
        $item = CartItem::where('user_id', $request->user()->id)->find($id);

        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Item not found'], 404);
        }

        $item->delete();

        return response()->json(['success' => true, 'message' => 'Item removed from cart']);
    }
}