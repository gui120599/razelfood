<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\ManagesSaleOrders;
use App\Enums\OrderStatus;
use App\Exceptions\SaleException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use App\Support\Sales\Money;
use App\Support\Sales\OpenSale;
use Illuminate\Support\Facades\DB;

/**
 * Lança itens de um pedido num rascunho (aba Pedidos): todos os itens livres do pedido ou só os
 * informados. O item fica RESERVADO à venda (`order_items.sale_id`): outro operador não o lança
 * em outra venda e o pedido deixa de ser editável/cancelável até o item sair ou a venda cair.
 *
 * Protocolo de lock (models-models.md): o pedido é travado PRIMEIRO e a guarda é reavaliada sobre
 * a linha travada; itens são lidos sem lock e atualizados por PK (`order_items` não tem tenant_id).
 * O frete do pedido entra no frete da venda se nenhuma outra venda ativa já o cobrou.
 */
class AttachOrderItems
{
    use ManagesSaleOrders;

    public function __construct(private readonly SyncSaleTotals $syncTotals) {}

    /**
     * @param  list<int>|null  $itemIds  null = todos os itens livres do pedido
     *
     * @throws SaleException
     */
    public function __invoke(Sale $sale, int $orderId, ?array $itemIds = null): Sale
    {
        $cutoff = OpenSale::pdvCutoff();

        return DB::transaction(function () use ($sale, $orderId, $itemIds, $cutoff): Sale {
            $order = Order::query()->lockForUpdate()->find($orderId)
                ?? throw new SaleException('Pedido não encontrado.');
            $locked = OpenSale::lock($sale);
            // Só depois dos locks: qualquer SELECT comum antes fixaria um snapshot defasado.
            $order->load('client');

            if ($order->status === OrderStatus::Cancelled) {
                throw new SaleException("O pedido {$order->displayNumber()} está cancelado e não pode entrar em uma venda.");
            }

            if ($order->status === OrderStatus::Started) {
                throw new SaleException("O pedido {$order->displayNumber()} ainda não foi aceito e não pode entrar em uma venda.");
            }

            if ($order->created_at->lt($cutoff)) {
                throw new SaleException("O pedido {$order->displayNumber()} é anterior à ativação do PDV e não pode entrar em uma venda.");
            }

            $items = OrderItem::query()->where('order_id', $order->id)->get(['id', 'sale_id']);

            if ($itemIds !== null) {
                $itemIds = array_values(array_unique(array_map('intval', $itemIds)));

                if ($items->whereIn('id', $itemIds)->count() !== count($itemIds)) {
                    throw new SaleException("Um ou mais itens não pertencem ao pedido {$order->displayNumber()}.");
                }

                $items = $items->whereIn('id', $itemIds);
            }

            // Itens pedidos explicitamente que já estão em OUTRA venda são recusados; no lançamento
            // "todos os itens livres" os que estão em outra venda simplesmente não entram.
            if ($itemIds !== null && $items->contains(fn (OrderItem $item): bool => $item->sale_id !== null && $item->sale_id !== $locked->id)) {
                throw new SaleException("Um item do pedido {$order->displayNumber()} já foi lançado em outra venda.");
            }

            $free = $items->whereNull('sale_id');

            if ($free->isEmpty()) {
                if ($items->contains(fn (OrderItem $item): bool => $item->sale_id === $locked->id)) {
                    return $locked;
                }

                throw new SaleException("O pedido {$order->displayNumber()} não tem itens livres para lançar.");
            }

            OrderItem::query()->whereKey($free->modelKeys())->update(['sale_id' => $locked->id]);

            $charged = $this->syncSaleOrderLine($locked, $order);

            $attributes = [];

            if ($charged > 0) {
                $attributes['delivery_fee'] = Money::fromCents(Money::toCents($locked->delivery_fee) + $charged);
            }

            if ($attributes !== []) {
                $locked->update($attributes);
            }

            $this->syncClientFromOrders($locked);

            return ($this->syncTotals)($locked);
        }, attempts: 3);
    }
}
