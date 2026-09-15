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
        'shipping_cost',
        'courier_code',
        'courier_service',
        'courier_etd',
        'tracking_number',
        'paid_at',
        'tracking_snapshot',
        'tracking_checked_at',
        'shipped_at',
        'complated_at',
        'completed_by',
        'cancelled_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'shipping_address' => 'array',
        'shipping_cost'       => 'decimal:2',
        'tracking_snapshot'   => 'array',
        'tracking_checked_at' => 'datetime',
        'shipped_at'          => 'datetime',
        'paid_at'      => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at'     => 'datetime',
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

    /**
     * Hanya pesanan yang belum dibayar yang boleh dibatalkan sendiri.
     *
     * Sebelumnya status 'paid' ikut di sini. Membatalkannya mengembalikan
     * stok dan menandai pesanan 'cancelled', tapi tidak ada satu pun jalur
     * refund di aplikasi ini: PaymentOrder tetap 'paid' dan uang pembeli
     * mengendap tanpa catatan kewajiban. Sampai alur refund benar-benar ada,
     * pembatalan pesanan berbayar adalah urusan dukungan, bukan tombol yang
     * bisa ditekan sendiri.
     */
    public function isCancellable(): bool
    {
        return $this->status === 'pending';
    }
}