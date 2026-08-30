<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;

class BusinessHour extends TenantScopedModel
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'weekday',
        'opens_at',
        'closes_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime:H:i:s',
            'closes_at' => 'datetime:H:i:s',
            'is_active' => 'boolean',
        ];
    }
}
