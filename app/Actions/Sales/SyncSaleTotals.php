<?php

namespace App\Actions\Sales;

use App\Models\OrderItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\Sales\Money;

/**
 * Recalcula os totais de uma venda em rascunho a partir do que está nela (tudo em centavos):
 * itens de pedidos (`order_items.sale_id`) + itens avulsos, desconto manual (percentual aplicado
 * sobre os itens, meio centavo para cima) e frete. `discount_total` é só o desconto informativo
 * das promoções dos pedidos. Chamar DENTRO da transação da Action, com a venda já travada.
 */
class SyncSaleTotals
{
    public function __invoke(Sale $sale): Sale
    {
        $orderItemsCents = 0;
        $promoDiscountCents = 0;

        foreach (OrderItem::query()->where('sale_id', $sale->id)->get(['id', 'quantity', 'unit_price', 'original_unit_price', 'addons_total']) as $item) {
            $quantity = (int) $item->quantity;
            $unitCents = Money::toCents($item->unit_price);

            $orderItemsCents += ($unitCents + Money::toCents($item->addons_total)) * $quantity;
            $promoDiscountCents += max(0, Money::toCents($item->original_unit_price ?? $item->unit_price) - $unitCents) * $quantity;
        }

        $standaloneCents = SaleItem::query()->where('sale_id', $sale->id)->get(['id', 'total'])
            ->sum(fn (SaleItem $item): int => Money::toCents($item->total));

        $itemsCents = $orderItemsCents + $standaloneCents;
        $manualDiscountCents = $this->manualDiscountCents($sale, $itemsCents);

        $sale->update([
            'items_total' => Money::fromCents($itemsCents),
            'discount_total' => Money::fromCents($promoDiscountCents),
            'manual_discount_total' => Money::fromCents($manualDiscountCents),
            'total' => Money::fromCents($itemsCents - $manualDiscountCents + Money::toCents($sale->delivery_fee)),
        ]);

        return $sale;
    }

    private function manualDiscountCents(Sale $sale, int $itemsCents): int
    {
        if ($sale->discount_percent === null) {
            return 0;
        }

        $hundredths = (int) round((float) $sale->discount_percent * 100);

        return min($itemsCents, intdiv($itemsCents * $hundredths + 5000, 10000));
    }
}
