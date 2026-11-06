<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de uma NFC-e como foi enviado à NFE.io (snapshot da emissão). Gravado só por
 * IssueFiscalDocument; base do relatório fiscal.
 */
class FiscalDocumentItem extends TenantScopedModel
{
    protected $fillable = [
        'tenant_id',
        'fiscal_document_id',
        'position',
        'product_id',
        'product_name',
        'description',
        'category_name',
        'ncm',
        'cfop',
        'csosn',
        'origin_code',
        'unit',
        'quantity',
        'unit_amount',
        'total_amount',
        'others_amount',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'integer',
            'unit_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'others_amount' => 'decimal:2',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }
}
