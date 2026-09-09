<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Address extends Model
{
    protected $fillable = [
        'user_id',
        'label',
        'recipient_name',
        'phone',
        'street',
        'district',
        'city',
        'province',
        'postal_code',
        'courier_note',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The first address a buyer saves becomes their default, so checkout
        // always has something to preselect.
        static::creating(function (Address $address) {
            if (!static::where('user_id', $address->user_id)->exists()) {
                $address->is_default = true;
            }
        });

        // Deleting the default promotes the next one, otherwise the buyer ends
        // up with addresses but nothing selected at checkout.
        static::deleted(function (Address $address) {
            if (!$address->is_default) {
                return;
            }

            $next = static::where('user_id', $address->user_id)
                ->orderBy('id')
                ->first();

            $next?->update(['is_default' => true]);
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Make this the buyer's default, clearing the previous one.
     *
     * Both writes happen together — a moment where a buyer has two defaults,
     * or none, would leave checkout picking arbitrarily.
     */
    public function makeDefault(): void
    {
        DB::transaction(function () {
            static::where('user_id', $this->user_id)
                ->where('id', '!=', $this->id)
                ->update(['is_default' => false]);

            $this->update(['is_default' => true]);
        });
    }

    /**
     * The frozen copy stored on a transaction.
     *
     * Kept as a plain array rather than an id, so editing this address later
     * can't rewrite where past orders were sent.
     */
    public function toSnapshot(): array
    {
        return [
            'recipient_name' => $this->recipient_name,
            'phone' => $this->phone,
            'street' => $this->street,
            'district' => $this->district,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postal_code,
            'courier_note' => $this->courier_note,
        ];
    }

    public function scopeDefaultFirst($query)
    {
        return $query->orderByDesc('is_default')->orderByDesc('id');
    }
}