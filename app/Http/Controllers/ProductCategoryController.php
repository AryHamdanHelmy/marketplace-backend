<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    public function index(){
        $category = ProductCategory::all();
        return response()->json([
            'succes' => true,
            'message' => 'Data kategori berhasil diambil',
            'data' => $category,
        ]);
    }
    public function show($id){
        $category = ProductCategory::findOrFail($id);
        return response()->json([
            'succes' => true,
            'message' => 'Data kategori berhasil diambil',
            'data' => $category
        ]);
    }
    public function store(Request $request){
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'icon' => 'nullable|string',
        ]);
        $category = ProductCategory::create($validated);
        return response()->json([
            'success' => true,
            'message' => 'Kategori berhasil ditambah',
            'data' => $category,
        ], 201);
    }
    public function update(Request $request, $id){
        $category = ProductCategory::findOrFail($id);
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'icon' => 'nullable|string',
        ]);
        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Kategori berhasil di update',
            'data' => $category,
        ]);
    }
    public function destroy($id)
    {
        $category = ProductCategory::findOrFail($id);
        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kategori berhasil dihapus',
        ]);
    }
}
