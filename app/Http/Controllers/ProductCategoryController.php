<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    public function index(Request $request)
    {
        if ($request->boolean('popular')) {
            $limit = (int) $request->query('limit', 10);

            $categories = ProductCategory::withCount(['products' => function ($q) {
                    $q->where('status', 'active');
                }])
                ->with('parent:id,name')
                ->get()
                ->sortByDesc(fn($c) => $c->products_count)
                ->take($limit)
                ->map(fn($c) => [
                    'id'            => $c->id,
                    'name'          => $c->name,
                    'icon'          => $c->icon,
                    'image_url'     => $c->image_url,
                    'parent_name'   => $c->parent?->name,
                    'is_parent'     => $c->parent_id === null,
                    'product_count' => $c->products_count,
                ])
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Data kategori berhasil diambil',
                'data'    => $categories,
            ]);
        }
        // ?flat=1 → daftar datar sub-kategori saja, untuk dropdown form
        if ($request->boolean('flat')) {
            $categories = ProductCategory::whereNotNull('parent_id')
                ->with('parent:id,name')
                ->orderBy('parent_id')
                ->orderBy('sort_order')
                ->get()
                ->map(fn($c) => [
                    'id'          => $c->id,
                    'name'        => $c->name,
                    'parent_id'   => $c->parent_id,
                    'parent_name' => $c->parent?->name,
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Data kategori berhasil diambil',
                'data'    => $categories,
            ]);
        }

        // Default → bertingkat
        $categories = ProductCategory::parents()
            ->with('children')
            ->withCount('products')
            ->orderBy('sort_order')
            ->get()
            ->map(fn($parent) => [
                'id'          => $parent->id,
                'name'        => $parent->name,
                'icon'        => $parent->icon,
                'image_url'   => $parent->image_url,
                'description' => $parent->description,
                'children'    => $parent->children->map(fn($child) => [
                    'id'   => $child->id,
                    'name' => $child->name,
                    'icon' => $child->icon,
                ]),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Data kategori berhasil diambil',
            'data'    => $categories,
        ]);
    }

    public function show($id)
    {
        $category = ProductCategory::find($id);
        if (!category){
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan',
            ], 404);
        }
        return response()->json([
            'success' => true,
            'message' => 'Data kategori berhasil diambil',
            'data'    => $category,
        ]);
    }

    public function store(Request $request)
    {
        // Admin only
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string',
            'image_url'   => 'nullable|string|max:500',
            'parent_id'   => 'nullable|exists:product_categories,id',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $category = ProductCategory::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Kategori berhasil ditambah',
            'data'    => $category,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        // Admin only
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $category = ProductCategory::findOrFail($id);

        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string',
            'image_url'   => 'nullable|string|max:500',
            'parent_id'   => 'nullable|exists:product_categories,id',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Kategori berhasil di update',
            'data'    => $category,
        ]);
    }

    public function destroy($id)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $category = ProductCategory::findOrFail($id);

        // Jangan hapus kalau masih ada sub-kategori
        if ($category->children()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Hapus sub-kategorinya terlebih dahulu',
            ], 422);
        }

        // Hitung termasuk produk yang soft-deleted, karena foreign key
        // di database juga menghitungnya
        $productCount = \App\Models\Product::withTrashed()
            ->where('category_id', $id)
            ->count();

        if ($productCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Masih ada {$productCount} produk di kategori ini",
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kategori berhasil dihapus',
        ]);
    }
}