<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $categoryIds = ProductCategory::pluck('id')->toArray();
        $sellerIds = User::pluck('id')->toArray();

        if (empty($categoryIds) || empty($sellerIds)) {
            $this->command->warn('Pastikan ProductCategorySeeder dan UserSeeder sudah dijalankan dulu.');
            return;
        }

        $products = [
            [
                'name' => 'Laptop Gaming ASUS ROG',
                'price' => 15000000,
                'stock' => 10,
                'description' => 'Laptop gaming dengan performa tinggi, cocok untuk kerja dan gaming.',
                'status' => 'active',
            ],
            [
                'name' => 'Mouse Wireless Logitech',
                'price' => 250000,
                'stock' => 50,
                'description' => 'Mouse wireless ergonomis dengan baterai tahan lama.',
                'status' => 'active',
            ],
            [
                'name' => 'Keyboard Mechanical RGB',
                'price' => 750000,
                'stock' => 25,
                'description' => 'Keyboard mechanical dengan lampu RGB dan switch responsif.',
                'status' => 'active',
            ],
        ];

        foreach ($products as $product) {
            Product::create([
                'category_id' => $categoryIds[array_rand($categoryIds)],
                'seller_id' => $sellerIds[array_rand($sellerIds)],
                'name' => $product['name'],
                'price' => $product['price'],
                'stock' => $product['stock'],
                'description' => $product['description'],
                'status' => $product['status'],
            ]);
        }
    }
}