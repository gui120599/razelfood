<?php

namespace App\Actions\Sales;

use App\Models\OrderItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleOrderItemDiscount;
use App\Support\Sales\Money;

/**
 * Recalcula os totais de uma venda em rascunho a partir do que está nela (tudo em centavos):
 * itens de pedidos (`order_items.sale_id`) + itens avulsos, desconto manual (descontos em reais por
 * linha + o percentual aplicado sobre o que sobra deles, meio centavo para cima) e frete. `discount_total` é só o desconto informativo
 * das promoções dos pedidos. Chamar DENTRO da transação da Action, com a venda já travada.
 */
class SyncSaleTotals
{
    public function __invoke(Sale $sale): Sale
    {
        $orderItemsCents = 0;
        $promoDiscountCents = 0;
        $lineDiscountCents = 0;
        $orderItemDiscounts = SaleOrderItemDiscount::query()->where('sale_id', $sale->id)->pluck('amount', 'order_item_id');

        foreach (OrderItem::query()->where('sale_id', $sale->id)->get(['id', 'quantity', 'unit_price', 'original_unit_price', 'addons_total']) as $item) {
            $quantity = (int) $item->quantity;
            $unitCents = Money::toCents($item->unit_price);
            $grossCents = ($unitCents + Money::toCents($item->addons_total)) * $quantity;

            $orderItemsCents += $grossCents;
            $lineDiscountCents += min($grossCents, Money::toCents($orderItemDiscounts[$item->id] ?? 0));
            $promoDiscountCents += max(0, Money::toCents($item->original_unit_price ?? $item->unit_price) - $unitCents) * $quantity;
        }

        $standaloneCents = 0;

        foreach (SaleItem::query()->where('sale_id', $sale->id)->get(['id', 'total', 'discount_amount']) as $item) {
            $grossCents = Money::toCents($item->total);

            $standaloneCents += $grossCents;
            $lineDiscountCents += min($grossCents, Money::toCents($item->discount_amount));
        }

        $itemsCents = $orderItemsCents + $standaloneCents;
        // Desconto manual = descontos por linha (Desc. R$) + o percentual sobre o que sobrou deles.
        $manualDiscountCents = $lineDiscountCents + $this->percentDiscountCents($sale, $itemsCents - $lineDiscountCents);

        $sale->update([
            'items_total' => Money::fromCents($itemsCents),
            'discount_total' => Money::fromCents($promoDiscountCents),
            'manual_discount_total' => Money::fromCents($manualDiscountCents),
            'total' => Money::fromCents($itemsCents - $manualDiscountCents + Money::toCents($sale->delivery_fee)),
        ]);

        return $sale;
    }

    private function percentDiscountCents(Sale $sale, int $netCents): int
    {
        if ($sale->discount_percent === null) {
            return 0;
        }

        $hundredths = (int) round((float) $sale->discount_percent * 100);

        return min($netCents, intdiv($netCents * $hundredths + 5000, 10000));
    }
}
