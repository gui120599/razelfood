<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends TenantScopedModel
{
    protected $fillable = [
        'tenant_id',
        'name',
        'phone',
        'address',
        'zip_code',
        'street',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
