<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Claudinary\Claudinary;
class ProductController extends Controller
{
    // GET /api/products
    public function index(Request $request)
    {
        $query = Product::with(['category', 'seller', 'primaryImage']);

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        $allowedSorts = ['rating', 'price', 'download_count', 'created_at'];
        $sortBy = in_array($request->sort_by, $allowedSorts) ? $request->sort_by : 'created_at';
        $order = $request->order === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $order);

        $perPage = $request->query('per_page', 12);
        $products = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Data produk berhasil diambil',
            'data' => collect($products->items())->map(fn($product) => $this->formatProduct($product)),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'per_page'     => $products->perPage(),
                'total'        => $products->total(),
            ],
        ]);
    }

    // GET /api/products/{id}
    public function show($id)
    {
        $product = Product::with(['category', 'seller', 'primaryImage'])->find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail produk berhasil diambil',
            'data'    => $this->formatProduct($product),
        ]);
    }

    // POST /api/products
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'category_id' => 'required|exists:product_categories,id',
                'name'        => 'required|string|max:150',
                'description' => 'nullable|string',
                'price'       => 'required|numeric|min:0',
                'stock'       => 'nullable|integer|min:0',
                'rating'      => 'nullable|numeric|min:0|max:10',
                'status'      => 'nullable|in:draft,active,inactive',
                'file_path'   => 'nullable|string',
                'download_count' => 'nullable|integer|min:0',
                'thumbnail'   => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors'  => $e->errors(),
            ], 422);
        }

        $validated['seller_id'] = auth()->id();

        // Simpan produk dulu tanpa thumbnail
        $product = Product::create(collect($validated)->except('thumbnail')->toArray());

        // Upload thumbnail kalau ada
        if ($request->hasFile('thumbnail')) {
            $uploadedFile = cloudinary()->upload($request->file('image')->getRealPath(), [
                'folder' => 'products',
                ])->getSecurePath();

            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $path,
                'is_primary' => true,
                'sort_order' => 0,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil ditambahkan',
            'data'    => $this->formatProduct($product->load(['category', 'seller', 'primaryImage'])),
        ], 201);
    }

    // PUT /api/products/{id}
    public function update(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan',
            ], 404);
        }

        if ($product->seller_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengubah data ini',
            ], 403);
        }

        $validated = $request->validate([
            'category_id'    => 'sometimes|exists:product_categories,id',
            'name'           => 'sometimes|string|max:150',
            'description'    => 'nullable|string',
            'price'          => 'sometimes|numeric|min:0',
            'stock'          => 'nullable|integer|min:0',
            'rating'         => 'nullable|numeric|min:0|max:5',
            'status'         => 'nullable|in:draft,active,inactive',
            'file_path'      => 'nullable|string',
            'download_count' => 'nullable|integer|min:0',
            'thumbnail'      => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $product->update(collect($validated)->except('thumbnail')->toArray());

        // Update thumbnail kalau ada file baru
        if ($request->hasFile('thumbnail')) {
            $path = $request->file('thumbnail')->store('products', 'public');

            // Hapus primary image lama, ganti yang baru
            $product->images()->where('is_primary', true)->delete();

            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $path,
                'is_primary' => true,
                'sort_order' => 0,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil diupdate',
            'data'    => $this->formatProduct($product->load(['category', 'seller', 'primaryImage'])),
        ]);
    }

    // DELETE /api/products/{id}
    public function destroy($id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan',
            ], 404);
        }

        if ($product->seller_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus data ini',
            ], 403);
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil dihapus',
        ]);
    }

    // Helper
    private function getRatingLabel(float $rating): string
    {
        if ($rating >= 8.5) return 'Top Rated';
        if ($rating >= 7.0) return 'Popular';
        return 'Regular';
    }

    private function formatProduct($product): array
    {
        $imagePath = $product->primaryImage?->image_path;

        return [
            'id'             => $product->id,
            'title'          => $product->name,
            'description'    => $product->description,
            'price'          => $product->price,
            'stock'          => $product->stock,
            'rating'         => $product->rating,
            'rating_label'   => $this->getRatingLabel((float) $product->rating),
            'thumbnail'      => $imagePath
                                    ? asset('storage/' . $imagePath)
                                    : null,
            'file_path'      => $product->file_path,
            'download_count' => $product->download_count,
            'status'         => $product->status,
            'category'       => $product->category ? [
                'id'   => $product->category->id,
                'name' => $product->category->name,
            ] : null,
            'seller'         => $product->seller ? [
                'id'   => $product->seller->id,
                'name' => $product->seller->name,
            ] : null,
        ];
    }
}