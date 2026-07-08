<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'seller_id',
        'category_id',
        'name',
        'description',
        'price',
        'stock',
        'status',
        'rating',
        'file_path',
        'download_count',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
    ];

    // Relasi ke User (seller)
    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    // Relasi ke ProductCategory
    public function category()
    {
        return $this->belongsTo(ProductCategory::class);
    }

    // Relasi ke ProductImage
    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function primaryImage()
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    // Relasi ke Review
    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    // Relasi ke TransactionItem
    public function transactionItems()
    {
        return $this->hasMany(TransactionItem::class);
    }
}
