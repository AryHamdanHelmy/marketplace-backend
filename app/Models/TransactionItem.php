<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionItem extends Model
{
    // Tabel ini cuma punya created_at, tanpa updated_at
    public $timestamps = false;

    protected $fillable = [
        'transaction_id',
        'product_id',
        'product_name',
        'product_thumbnail',
        'product_price',
        'quantity',
        'subtotal',
    ];

    protected $casts = [
        'product_price' => 'decimal:2',
        'quantity'      => 'integer',
        'subtotal'      => 'decimal:2',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}