<?php

namespace App\Actions\Sales\Concerns;

use App\Actions\Sales\SyncSaleTotals;
use App\Enums\SaleStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use App\Models\SaleOrder;
use App\Support\CurrentTenant;
use App\Support\Sales\Money;

/**
 * Linhas `sale_orders` de um rascunho (um pedido com itens na venda) e o frete que cada pedido
 * traz para a venda. O frete de um pedido é cobrado UMA vez só, na primeira venda ativa (aberta
 * ou finalizada) em que o pedido entra; devolve-se ao tirar o último item dele do rascunho.
 */
trait ManagesSaleOrders
{
    /**
     * Cria ou atualiza a linha do pedido na venda e devolve o frete (em centavos) que ela passou
     * a cobrar nesta chamada (0 se a linha já existia ou se outra venda ativa já cobrou o frete).
     */
    protected function syncSaleOrderLine(Sale $sale, Order $order): int
    {
        $line = SaleOrder::query()->where('sale_id', $sale->id)->where('order_id', $order->id)->first();
        $newlyCharged = 0;

        if ($line === null) {
            $newlyCharged = $this->freightChargedElsewhere($sale, $order) ? 0 : Money::toCents($order->delivery_fee);

            $line = new SaleOrder([
                'tenant_id' => CurrentTenant::id(),
                'sale_id' => $sale->id,
                'order_id' => $order->id,
                'delivery_fee_charged' => Money::fromCents($newlyCharged),
            ]);
        }

        $line->fill([
            'order_label' => $order->displayNumber(),
            'order_status' => $order->status,
            'client_name' => $order->client?->name,
            'order_total' => Money::fromCents($this->orderItemsCents($sale, $order) + Money::toCents($line->delivery_fee_charged)),
        ])->save();

        return $newlyCharged;
    }

    /**
     * Soma, em centavos, dos itens do pedido que estão nesta venda (preço + adicionais, × quantidade).
     */
    protected function orderItemsCents(Sale $sale, Order $order): int
    {
        return OrderItem::query()->where('sale_id', $sale->id)->where('order_id', $order->id)
            ->get(['id', 'quantity', 'unit_price', 'addons_total'])
            ->sum(fn (OrderItem $item): int => (Money::toCents($item->unit_price) + Money::toCents($item->addons_total)) * (int) $item->quantity);
    }

    private function freightChargedElsewhere(Sale $sale, Order $order): bool
    {
        return SaleOrder::query()
            ->where('order_id', $order->id)
            ->where('sale_id', '!=', $sale->id)
            ->where('delivery_fee_charged', '>', 0)
            ->whereHas('sale', fn ($query) => $query->whereIn('status', [SaleStatus::Open->value, SaleStatus::Finalized->value]))
            ->exists();
    }

    /**
     * Passa o frete que `$from` cobrava do pedido para outra venda ABERTA com itens do mesmo pedido
     * (a que ainda não o cobra), senão o frete se perderia quando a primeira venda cai. Ninguém
     * mais cobra: venda finalizada não muda, e então o frete fica sem cobrança. O pedido já está
     * travado por quem chama, então duas transferências do mesmo pedido nunca se cruzam.
     */
    protected function transferFreight(Sale $from, int $orderId, int $feeCents): void
    {
        if ($feeCents <= 0) {
            return;
        }

        $target = SaleOrder::query()
            ->where('order_id', $orderId)
            ->where('sale_id', '!=', $from->id)
            ->where('delivery_fee_charged', 0)
            ->whereHas('sale', fn ($query) => $query->where('status', SaleStatus::Open->value))
            ->orderBy('sale_id')
            ->first();

        if ($target === null) {
            return;
        }

        $other = Sale::query()->whereKey($target->sale_id)->lockForUpdate()->first();

        if ($other === null || $other->status !== SaleStatus::Open) {
            return;
        }

        $target->update([
            'delivery_fee_charged' => Money::fromCents($feeCents),
            'order_total' => Money::fromCents(Money::toCents($target->order_total) + $feeCents),
        ]);
        $other->update(['delivery_fee' => Money::fromCents(Money::toCents($other->delivery_fee) + $feeCents)]);

        app(SyncSaleTotals::class)($other);
    }

    /**
     * Cliente da venda a partir dos pedidos lançados, enquanto o operador não escolheu um (ou
     * limpou): só quando TODOS os pedidos são do mesmo cliente; com clientes diferentes (ou um
     * pedido sem cliente) fica sem cliente, e a NFC-e sai sem comprador identificado.
     */
    protected function syncClientFromOrders(Sale $sale): void
    {
        if ($sale->client_manual) {
            return;
        }

        $clientIds = Order::query()
            ->whereIn('id', SaleOrder::query()->where('sale_id', $sale->id)->select('order_id'))
            ->pluck('client_id')
            ->unique()
            ->values();

        $clientId = $clientIds->count() === 1 ? $clientIds->first() : null;

        if ($sale->client_id !== $clientId) {
            $sale->update(['client_id' => $clientId]);
        }
    }
}
