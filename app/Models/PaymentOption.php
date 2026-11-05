<?php

namespace App\Models;

use App\Enums\FiscalPaymentMethod;
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
        'fiscal_payment_code',
        'auto_issue_fiscal_document',
        'requires_card_brand',
        'requires_authorization_code',
    ];

    protected function casts(): array
    {
        return [
            'show_in_menu' => 'boolean',
            'is_cash' => 'boolean',
            'fiscal_payment_code' => FiscalPaymentMethod::class,
            'auto_issue_fiscal_document' => 'boolean',
            'requires_card_brand' => 'boolean',
            'requires_authorization_code' => 'boolean',
        ];
    }
}
