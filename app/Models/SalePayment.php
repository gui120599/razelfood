<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pagamento RECEBIDO de uma venda. `amount` é o valor entregue; o líquido
 * (que abate o total da venda) é `amount - change_amount`.
 */
class SalePayment extends TenantScopedModel
{
    protected $fillable = [
        'tenant_id',
        'sale_id',
        'payment_option_id',
        'payment_option_name',
        'is_cash',
        'amount',
        'change_amount',
        'card_brand',
        'card_brand_id',
        'authorization_code',
        'payment_terminal_id',
    ];

    protected function casts(): array
    {
        return [
            'is_cash' => 'boolean',
            'amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function paymentOption(): BelongsTo
    {
        return $this->belongsTo(PaymentOption::class);
    }

    public function cardBrand(): BelongsTo
    {
        return $this->belongsTo(CardBrand::class);
    }

    public function netAmount(): string
    {
        return number_format((float) $this->amount - (float) $this->change_amount, 2, '.', '');
    }
}
