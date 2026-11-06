<?php

namespace App\Actions\Sales;

use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Models\Sale;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\Sales\OpenSale;

/**
 * Abre um rascunho de venda (D-08): sem número, sem baixa de estoque, sem efeito fiscal. A tela
 * Nova Venda cria o rascunho no primeiro lançamento (item, pedido ou cliente).
 */
class StartSale
{
    /**
     * @throws SaleException
     */
    public function __invoke(?User $openedBy = null): Sale
    {
        OpenSale::pdvCutoff();

        return Sale::create([
            'tenant_id' => CurrentTenant::id(),
            'status' => SaleStatus::Open,
            'items_total' => 0,
            'discount_total' => 0,
            'manual_discount_total' => 0,
            'delivery_fee' => 0,
            'total' => 0,
            'opened_by_user_id' => $openedBy?->id,
        ]);
    }
}
