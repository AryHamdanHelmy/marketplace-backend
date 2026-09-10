<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductController extends Controller
{
    // GET /api/products
    public function index(Request $request)
    {
        $query = Product::with(['category', 'seller.store', 'primaryImage']);

        // Sellers managing their own catalogue pass mine=1. Everyone else —
        // shoppers browsing, admins moderating — sees the whole marketplace,
        // which is why this endpoint stays public.
        if ($request->boolean('mine')) {
            if (!$request->user()) {
                abort(401, 'Sign in first.');
            }
            $query->where('seller_id', $request->user()->id);
        }
        
        if ($request->has('seller_id')) {
            $query->where('seller_id', $request->seller_id);
        }

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->has('category_id')) {
            $categoryId = $request->category_id;

            // Kalau yang dipilih kategori induk, sertakan semua sub-kategorinya
            $childIds = ProductCategory::where('parent_id', $categoryId)->pluck('id');

            if ($childIds->isNotEmpty()) {
                $query->whereIn('category_id', $childIds);
            } else {
                $query->where('category_id', $categoryId);
            }
        }

        if ($request->status === 'all') {
            // sengaja tidak difilter
        } elseif ($request->has('status')) {
            $query->where('status', $request->status);
        } else {
            $query->where('status', 'active');
        }

        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        if ($request->has('min_rating')) {
            $query->where('rating', '>=', $request->min_rating);
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
        $product = Product::with(['category', 'seller.store', 'primaryImage'])->find($id);

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
        if (!in_array(auth()->user()->role, ['seller', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only sellers can add products',
            ], 403);
        }

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
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        }

        if (!empty($validated['category_id'])) {
            $isParent = ProductCategory::where('id', $validated['category_id'])
                ->whereNull('parent_id')
                ->exists();

            if ($isParent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Choose a sub-category, not a top-level one',
                ], 422);
            }
        }

        // Uploaded before the product row exists, so a Cloudinary failure
        // doesn't leave a half-created product behind with no image and a
        // 500 the seller can't interpret.
        $uploadedUrl = null;

        if ($request->hasFile('thumbnail')) {
            $uploadedUrl = $this->uploadThumbnail($request);

            if ($uploadedUrl === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Image upload failed. Try again, or save without an image.',
                ], 422);
            }
        }

        $validated['seller_id'] = auth()->id();

        $product = Product::create(collect($validated)->except('thumbnail')->toArray());

        if ($uploadedUrl) {
            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $uploadedUrl,
                'is_primary' => true,
                'sort_order' => 0,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product created',
            'data'    => $this->formatProduct($product->load(['category', 'seller.store', 'primaryImage'])),
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

        if ($product->seller_id !== auth()->id() && auth()->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => "You don't have permission to change this product",
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

        if (!empty($validated['category_id'])) {
            $isParent = ProductCategory::where('id', $validated['category_id'])
                ->whereNull('parent_id')
                ->exists();

            if ($isParent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Choose a sub-category, not a top-level one',
                ], 422);
            }
        }

        // Same order as store(): upload first, so a failure leaves the
        // existing product and its current image untouched.
        $uploadedUrl = null;

        if ($request->hasFile('thumbnail')) {
            $uploadedUrl = $this->uploadThumbnail($request);

            if ($uploadedUrl === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Image upload failed. Your other changes were not saved either.',
                ], 422);
            }
        }

        $product->update(collect($validated)->except('thumbnail')->toArray());

        if ($uploadedUrl) {
            $product->images()->where('is_primary', true)->delete();

            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $uploadedUrl,
                'is_primary' => true,
                'sort_order' => 0,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product updated',
            'data'    => $this->formatProduct($product->load(['category', 'seller.store', 'primaryImage'])),
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

        if ($product->seller_id !== auth()->id() && auth()->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => "You don't have permission to delete this product",
            ], 403);
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted',
        ]);
    }

    // Helper

    /**
     * Push the uploaded image to Cloudinary.
     *
     * Returns the URL, or false when the upload failed. A misconfigured
     * CLOUDINARY_* variable throws from deep inside the SDK, and without this
     * the caller gets a bare 500 that says nothing about what went wrong.
     */
    private function uploadThumbnail(Request $request): string|false
    {
        try {
            $path = $request->file('thumbnail')->store('products', 'cloudinary');

            return Storage::disk('cloudinary')->url($path);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

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
            // image_path sekarang selalu berisi URL lengkap dari Cloudinary,
            // jadi dipakai apa adanya - tidak perlu asset('storage/...') lagi
            'thumbnail'      => $imagePath,
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
                // Named 'shop' to match what the frontend reads. The word
                // 'store' collides with Laravel's own store() everywhere else.
                'shop' => $product->seller->store ? [
                    'name'     => $product->seller->store->name,
                    'slug'     => $product->seller->store->slug,
                    'city'     => $product->seller->store->city,
                    'province' => $product->seller->store->province,
                    'is_open'  => $product->seller->store->is_open,
                ] : null,
            ] : null,
        ];
    }
}