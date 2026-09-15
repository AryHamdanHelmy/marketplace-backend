<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Withdrawal extends Model
{
    protected $fillable = [
        'seller_id',
        'reference',
        'amount',
        'status',
        'bank_name',
        'bank_account_number',
        'bank_account_holder',
        'transfer_reference',
        'note',
        'processed_by',
        'processed_at',
    ];

    // The full destination account stays server-side, same as on Store.
    protected $hidden = [
        'bank_account_number',
    ];

    protected $appends = [
        'masked_account_number',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'processed_at' => 'datetime',
            // Sama seperti Store: disalin saat penarikan dibuat, jadi harus
            // dilindungi di tempat yang sama pula.
            'bank_account_number' => 'encrypted',
        ];
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['pending', 'processing']);
    }

    // WD-20260908-4F2A — short enough to read out over the phone, unique
    // enough that the retry loop practically never runs twice.
    public static function generateReference(): string
    {
        do {
            $reference = 'WD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    public function getMaskedAccountNumberAttribute(): ?string
    {
        $number = $this->bank_account_number;

        return $number ? '•••• ' . substr($number, -4) : null;
    }
}