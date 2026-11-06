<?php

namespace App\Actions\Sales;

use App\Enums\SaleStatus;
use App\Models\OrderItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use Illuminate\Support\Facades\DB;

/**
 * Descarta um rascunho VAZIO (sem itens, pedidos nem pagamentos): ao sair da tela (sendBeacon) e
 * pelo comando agendado. Devolve false, sem apagar nada, se a venda não existe, não está mais
 * aberta ou ganhou conteúdo — a venda é relida sob lock, então um lançamento concorrente vence.
 */
class DiscardEmptySale
{
    public function __invoke(Sale|int $sale): bool
    {
        return DB::transaction(function () use ($sale): bool {
            $locked = Sale::query()->whereKey($sale instanceof Sale ? $sale->getKey() : $sale)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== SaleStatus::Open || $this->hasContent($locked)) {
                return false;
            }

            $locked->delete();

            return true;
        }, attempts: 3);
    }

    private function hasContent(Sale $sale): bool
    {
        return $sale->client_id !== null
            || SaleItem::query()->where('sale_id', $sale->id)->exists()
            || SalePayment::query()->where('sale_id', $sale->id)->exists()
            || OrderItem::query()->where('sale_id', $sale->id)->exists();
    }
}
