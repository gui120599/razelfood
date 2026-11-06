<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\ManagesSaleOrders;
use App\Exceptions\SaleException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use App\Models\SaleOrder;
use App\Support\Sales\Money;
use App\Support\Sales\OpenSale;
use Illuminate\Support\Facades\DB;

/**
 * Devolve itens de um pedido do rascunho (libera a reserva). Tirando o último item do pedido, a
 * linha do pedido some da venda e o frete que ele trouxe é devolvido. Mesmo protocolo de lock de
 * AttachOrderItems (pedido primeiro; itens por PK).
 */
class DetachOrderItems
{
    use ManagesSaleOrders;

    public function __construct(private readonly SyncSaleTotals $syncTotals) {}

    /**
     * @param  list<int>|null  $itemIds  null = todos os itens do pedido que estão nesta venda
     *
     * @throws SaleException
     */
    public function __invoke(Sale $sale, int $orderId, ?array $itemIds = null): Sale
    {
        return DB::transaction(function () use ($sale, $orderId, $itemIds): Sale {
            $order = Order::query()->lockForUpdate()->find($orderId)
                ?? throw new SaleException('Pedido não encontrado.');
            $locked = OpenSale::lock($sale);
            $order->load('client');

            $inSale = OrderItem::query()->where('order_id', $order->id)->where('sale_id', $locked->id)->get(['id']);
            $targets = $itemIds === null ? $inSale : $inSale->whereIn('id', array_map('intval', $itemIds));

            if ($targets->isEmpty()) {
                throw new SaleException("Nenhum item do pedido {$order->displayNumber()} está nesta venda.");
            }

            OrderItem::query()->whereKey($targets->modelKeys())->update(['sale_id' => null]);

            if ($this->orderItemsCents($locked, $order) === 0 && ! OrderItem::query()->where('order_id', $order->id)->where('sale_id', $locked->id)->exists()) {
                $line = SaleOrder::query()->where('sale_id', $locked->id)->where('order_id', $order->id)->first();
                $returned = Money::toCents($line?->delivery_fee_charged);

                $line?->delete();

                if ($returned > 0) {
                    $locked->update(['delivery_fee' => Money::fromCents(max(0, Money::toCents($locked->delivery_fee) - $returned))]);
                    $this->transferFreight($locked, $order->id, $returned);
                }
            } else {
                $this->syncSaleOrderLine($locked, $order);
            }

            $this->syncClientFromOrders($locked);

            return ($this->syncTotals)($locked);
        }, attempts: 3);
    }
}
