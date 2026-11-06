<?php

namespace App\Models;

use App\Enums\CardBrand as CardBrandEnum;
use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bandeira de cartão cadastrada pelo tenant (com CNPJ da credenciadora para a NFC-e).
 */
class CardBrand extends TenantScopedModel
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'brand',
        'name',
        'acquirer_cnpj',
    ];

    protected function casts(): array
    {
        return [
            'brand' => CardBrandEnum::class,
        ];
    }
}
