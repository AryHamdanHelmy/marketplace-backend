<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'checkout_group_id',
        'invoice_number',
        'shipping_address',
        'buyer_id',
        'seller_id',
        'seller_name',
        'status',
        'total_amount',
        'paid_at',
        'shipped_at',
        'complated_at',
        'completed_by',
        'cancelled_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'shipping_address' => 'array',
        'paid_at'      => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at'     => 'datetime',
        'cancelled_at'     => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, ['pending', 'paid']);
    }
}