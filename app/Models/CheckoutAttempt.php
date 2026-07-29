<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckoutAttempt extends Model
{
    protected $fillable = [
        'user_id',
        'idempotency_key',
        'checkout_group_id',
    ];
}