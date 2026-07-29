<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ambil id sub-kategori berdasarkan nama
        $sub = fn (string $name) => DB::table('product_categories')
            ->where('name', $name)
            ->whereNotNull('parent_id')
            ->value('id');

        $mapping = [
            'Mouse Wireless Logitech'       => $sub('Aksesoris Komputer'),
            'Logitech M330'                 => $sub('Aksesoris Komputer'),
            'Audio Technica M40X Headphone' => $sub('Audio & Headphone'),
            'Google pixel 9 12/512GB'       => $sub('Handphone & Tablet'),
            'Playstation 5 disk'            => $sub('Gaming & Konsol'),
            'Laptop Gaming ASUS ROG'        => $sub('Laptop'),
            'Gigabyte RTX3050 6GB'          => $sub('Komponen PC'),
        ];

        foreach ($mapping as $productName => $categoryId) {
            if (!$categoryId) {
                continue;   // sub-kategori tidak ditemukan, lewati
            }

            DB::table('products')
                ->where('name', $productName)
                ->update(['category_id' => $categoryId]);
        }

        // Hapus kategori lama, hanya kalau sudah tidak ada produk yang menempel
        DB::table('product_categories')
            ->whereIn('name', ['Aksesoris', 'Gadget', 'Pakaian'])
            ->whereNull('parent_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                      ->from('products')
                      ->whereColumn('products.category_id', 'product_categories.id');
            })
            ->delete();
    }

    public function down(): void
    {
        // Tidak dikembalikan — kategori lama sudah dihapus
    }
};