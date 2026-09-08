<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerBalance extends Model
{
    protected $fillable = [
        'seller_id',
        'balance',
    ];

    // The table carries updated_at only, so Laravel must be told not to look
    // for created_at — otherwise every write fails on an unknown column.
    public const CREATED_AT = null;

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
        ];
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function logs()
    {
        return $this->hasMany(BalanceLog::class, 'seller_id', 'seller_id');
    }

    // Every balance mutation goes through a locked read first. Using this
    // helper keeps that discipline in one place instead of relying on each
    // caller to remember lockForUpdate().
    public static function lockFor(int $sellerId): self
    {
        return static::firstOrCreate(
            ['seller_id' => $sellerId],
            ['balance' => 0]
        )->newQuery()
            ->where('seller_id', $sellerId)
            ->lockForUpdate()
            ->first();
    }
}