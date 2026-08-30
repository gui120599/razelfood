<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPayment extends Model
{
    protected $fillable = [
        'order_id',
        'payment_option_name',
        'is_cash',
        'amount',
        'change_for',
    ];

    protected function casts(): array
    {
        return [
            'is_cash' => 'boolean',
            'amount' => 'decimal:2',
            'change_for' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
