<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use Illuminate\Http\Request;
use App\Models\Product;


class CartController extends Controller
{
    // GET /api/cart
    public function index(Request $request)
    {
        $items = CartItem::with(['product.primaryImage', 'product.seller'])
            ->where('user_id', $request->user()->id)
            ->get();

        // Produk pakai SoftDeletes — kalau sudah dihapus, relasinya jadi null.
        // Item cart seperti ini tidak lagi bermakna, jadi dibersihkan.
        $orphaned = $items->filter(fn($item) => $item->product === null);

        if ($orphaned->isNotEmpty()) {
            CartItem::whereIn('id', $orphaned->pluck('id'))->delete();
            $items = $items->filter(fn($item) => $item->product !== null);
        }

        $formatted = $items->map(fn($item) => [
            'id'       => $item->id,
            'quantity' => $item->quantity,
            'product'  => [
                'id'        => $item->product->id,
                'title'     => $item->product->name,
                'price'     => $item->product->price,
                'thumbnail' => $item->product->primaryImage?->image_path,
                'seller'    => $item->product->seller?->name,
            ],
            'subtotal' => $item->quantity * $item->product->price,
        ])->values();

        return response()->json([
            'success' => true,
            'data'    => $formatted,
            'total'   => $formatted->sum('subtotal'),
        ]);
    }

    // POST /api/cart  { product_id, quantity? }
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'nullable|integer|min:1',
        ]);
        $product = Product::find($validated['product_id']);

        if (!$product || $product->status !== 'active') {
            return response()->json([
                'success' =>false,
                'message' => 'Product tidak tersedia',
            ], 422);
        }
        $item = CartItem::where('user_id', $request->user()->id)
            ->where('product_id', $validated['product_id'])
            ->first();
        $addQty = $validated['quantity'] ?? 1;
        $finalQty = $item ? $item->quantity + $addQty : $addQty;
        
        if ($finalQty > $product->stock) {
            return response()->json([
                'success' => false,
                'message' => "Stock tidak mencukupi, tersisa {$product->stock}",
            ], 422);
        }

        if ($item) {
            $item->quantity = $finalQty;
            $item->save();
        } else {
            $item = CartItem::create([
                'user_id' => $request->user()->id,
                'product_id' => $validated['product_id'],
                'quantity' => $addQty,
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
        $item = CartItem::with('product')
            ->where('user_id', $request->user()->id)
            ->find($id);

        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Item not found'], 404);
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        if (!$item->product) {
            return response()->json([
                'success' => false,
                'message' => 'Produk sudah tidak tersedia',
            ], 422);
        }

        if ($validated['quantity'] > $item->product->stock) {
            return response()->json([
                'success' => false,
                'message' => "Stok tidak mencukupi, tersisa {$item->product->stock}",
            ], 422);
        }

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