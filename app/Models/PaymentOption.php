<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentOption extends TenantScopedModel
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'show_in_menu',
        'is_cash',
    ];

    protected function casts(): array
    {
        return [
            'show_in_menu' => 'boolean',
            'is_cash' => 'boolean',
        ];
    }
}
