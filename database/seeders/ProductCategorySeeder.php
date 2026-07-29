<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ProductCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Komputer & Laptop',
                'icon' => 'laptop',
                'description' => 'Laptop, PC, dan komponen komputer',
                'children' => [
                    'Laptop',
                    'PC & Desktop',
                    'Komponen PC',
                    'Monitor',
                    'Storage & Memory',
                    'Aksesoris Komputer',
                ],
            ],
            [
                'name' => 'Elektronik',
                'icon' => 'smartphone',
                'description' => 'Gadget, audio, dan perangkat elektronik',
                'children' => [
                    'Handphone & Tablet',
                    'Audio & Headphone',
                    'Kamera',
                    'Gaming & Konsol',
                    'Smart Device',
                    'Aksesoris Gadget',
                ],
            ],
            [
                'name' => 'Fashion',
                'icon' => 'shirt',
                'description' => 'Pakaian, sepatu, dan aksesoris',
                'children' => [
                    'Pakaian Pria',
                    'Pakaian Wanita',
                    'Sepatu',
                    'Tas',
                    'Jam & Aksesoris',
                ],
            ],
            [
                'name' => 'Sembako & Kebutuhan Harian',
                'icon' => 'shopping-basket',
                'description' => 'Bahan pokok dan kebutuhan sehari-hari',
                'children' => [
                    'Beras & Biji-bijian',
                    'Minyak & Bumbu Dapur',
                    'Makanan Instan',
                    'Minuman',
                    'Perlengkapan Kebersihan',
                ],
            ],
            [
                'name' => 'Lainnya',
                'icon' => 'package',
                'description' => 'Produk yang belum masuk kategori lain',
                'children' => [],
            ],
        ];

        foreach ($categories as $index => $data) {
            $parent = ProductCategory::updateOrCreate(
                ['name' => $data['name'], 'parent_id' => null],
                [
                    'icon'        => $data['icon'],
                    'description' => $data['description'],
                    'sort_order'  => $index,
                ]
            );

            foreach ($data['children'] as $childIndex => $childName) {
                ProductCategory::updateOrCreate(
                    ['name' => $childName, 'parent_id' => $parent->id],
                    ['sort_order' => $childIndex]
                );
            }
        }
    }
}