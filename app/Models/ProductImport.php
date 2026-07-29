<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImport extends Model
{
    protected $fillable = [
        'user_id',
        'file_name',
        'total_rows',
        'success_count',
        'failed_count',
        'status',
        'errors',
    ];

    protected $casts = [
        'errors'        => 'array',
        'total_rows'    => 'integer',
        'success_count' => 'integer',
        'failed_count'  => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
