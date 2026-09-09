<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentOrder extends Model
{
    protected $fillable = [
        'checkout_group_id',
        'buyer_id',
        'reference',
        'amount',
        'gateway',
        'channel',
        'status',
        'gateway_reference',
        'instructions',
        'payload',
        'expires_at',
        'paid_at',
    ];

    // The raw gateway payload can contain signatures and internal ids. It is
    // kept for support and reconciliation, never sent to the browser.
    protected $hidden = [
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'instructions' => 'array',
            'payload' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    // Every seller order this single charge covers.
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'checkout_group_id', 'checkout_group_id');
    }

    // Gateways reject a reused order id permanently, so a retried checkout
    // needs a reference that has never been sent before. The random suffix
    // means retrying the same cart still produces a fresh one.
    public static function generateReference(): string
    {
        do {
            $reference = 'RPK-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    public function isPayable(): bool
    {
        if (!in_array($this->status, ['pending', 'awaiting_payment'])) {
            return false;
        }

        return !$this->expires_at || $this->expires_at->isFuture();
    }

    public function isSettled(): bool
    {
        return in_array($this->status, ['paid', 'refunded']);
    }

    public function scopeAwaiting($query)
    {
        return $query->whereIn('status', ['pending', 'awaiting_payment']);
    }
}