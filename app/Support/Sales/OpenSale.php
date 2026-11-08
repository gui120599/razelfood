<?php

namespace App\Support\Sales;

use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use Illuminate\Support\Carbon;

/**
 * Pré-condições comuns das Actions do rascunho (venda `open`): relê a venda SOB LOCK e exige
 * que ainda esteja aberta (duas abas / dois operadores / descarte concorrente), e confere o PDV.
 *
 * Ordem de lock das Actions de venda: pedidos (por id) -> venda -> produtos -> tenants.
 */
final class OpenSale
{
    /**
     * Data de corte do PDV (lida do banco, a fonte de verdade); sem ela o PDV não está ativo.
     *
     * @throws SaleException
     */
    public static function pdvCutoff(): Carbon
    {
        $tenant = CurrentTenant::get() ?? throw new SaleException('Nenhum estabelecimento ativo para registrar a venda.');
        $cutoff = $tenant->hasFeature(FeatureKey::PDV) ? PdvCutoff::for($tenant) : null;

        return $cutoff ?? throw new SaleException('O PDV não está ativo para este estabelecimento.');
    }

    /**
     * Relê a venda com lock e exige status Aberta. Deve ser chamada DENTRO de uma transação.
     *
     * @throws SaleException
     */
    public static function lock(Sale|int $sale): Sale
    {
        $locked = Sale::query()->whereKey($sale instanceof Sale ? $sale->getKey() : $sale)->lockForUpdate()->first()
            ?? throw new SaleException('Venda não encontrada.');

        if ($locked->status !== SaleStatus::Open) {
            throw new SaleException("A venda {$locked->displayNumber()} não está mais aberta.");
        }

        return $locked;
    }

    /**
     * Ids (ordenados) dos pedidos que têm itens nesta venda ou foram marcados `orders.sale_id`.
     * Leitura comum: fora da transação serve para saber QUAIS pedidos travar primeiro; dentro dela,
     * depois dos locks, confirma que o conjunto não mudou (o snapshot do REPEATABLE READ nasce no
     * primeiro SELECT comum da transação, então a leitura que vale é a pós-lock).
     *
     * @return list<int>
     */
    public static function orderIdsOf(int $saleId): array
    {
        return OrderItem::query()->where('sale_id', $saleId)->pluck('order_id')
            ->merge(Order::query()->where('sale_id', $saleId)->pluck('id'))
            ->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
    }
}
