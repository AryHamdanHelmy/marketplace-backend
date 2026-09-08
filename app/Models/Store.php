<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'seller_id',
        'name',
        'slug',
        'description',
        'logo_url',
        'banner_url',
        'city',
        'province',
        'is_open',
        'bank_name',
        'bank_account_number',
        'bank_account_holder',
    ];

    // The raw account number never leaves the server. Anything that needs to
    // display it uses masked_account_number instead.
    protected $hidden = [
        'bank_account_number',
    ];

    protected $appends = [
        'masked_account_number',
    ];

    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Store $store) {
            if (empty($store->slug)) {
                $store->slug = static::uniqueSlug($store->name);
            }
        });

        static::updating(function (Store $store) {
            // Only regenerate when the shop is renamed, so existing links
            // keep working for shops that were merely edited.
            if ($store->isDirty('name') && !$store->isDirty('slug')) {
                $store->slug = static::uniqueSlug($store->name, $store->id);
            }
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'shop';
        $slug = $base;
        $suffix = 1;

        while (
            static::where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base . '-' . ++$suffix;
        }

        return $slug;
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    // Products are owned by the seller rather than the shop, so this hops
    // across seller_id instead of a store_id foreign key.
    public function products()
    {
        return $this->hasMany(Product::class, 'seller_id', 'seller_id');
    }

    // "BCA •••• 8820" — enough for the seller to recognise the account
    // without the full number sitting in an API response.
    public function getMaskedAccountNumberAttribute(): ?string
    {
        $number = $this->attributes['bank_account_number'] ?? null;

        if (!$number) {
            return null;
        }

        return '•••• ' . substr($number, -4);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}