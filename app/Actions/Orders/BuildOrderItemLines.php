<?php

namespace App\Actions\Orders;

use App\Models\Addon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Monta as linhas de item de um pedido já persistido no mesmo formato usado
 * no checkout/painel: nome (combo = sabores unidos por " / "), categoria,
 * adicionais e total por linha. Resolve produto/sabor/adicional em runtime
 * com withTrashed() — o OrderItem não guarda snapshot de nome (ver
 * app/Models/OrderItem.php). Compartilhado entre a página de acompanhamento
 * (OrderTrackingController) e a comanda impressa (OrderTicketController).
 *
 * @return Collection<int, array{name: string, category_name: ?string, quantity: int, note: ?string, addons_display: array<int, string>, gifts_display: array<int, string>, line_total: float}>
 */
class BuildOrderItemLines
{
    public function __invoke(Order $order): Collection
    {
        $items = $order->items;

        $addonIds = $items->flatMap(fn (OrderItem $item) => $item->addons ?? [])->pluck('addon_id')->unique();
        $addonNames = $addonIds->isEmpty() ? collect() : Addon::withTrashed()->whereIn('id', $addonIds)->pluck('name', 'id');

        $giftIds = $items->flatMap(fn (OrderItem $item) => $item->gifts ?? [])->pluck('gift_product_id')->unique();
        $giftNames = $giftIds->isEmpty() ? collect() : Product::withTrashed()->whereIn('id', $giftIds)->pluck('name', 'id');

        $flavorIds = $items->flatMap(fn (OrderItem $item) => $item->flavors ?? [])->unique();
        $flavorProducts = $flavorIds->isEmpty()
            ? collect()
            : Product::withTrashed()->whereIn('id', $flavorIds)->with('category')->get()->keyBy('id');

        return $items->map(function (OrderItem $item) use ($addonNames, $giftNames, $flavorProducts) {
            if ($item->flavors) {
                $flavors = collect($item->flavors)->map(fn (int $id) => $flavorProducts->get($id))->filter();
                $name = $flavors->pluck('name')->implode(' / ');
                $categoryName = $flavors->first()?->category?->name;
            } else {
                $name = $item->product?->name ?? 'Produto removido';
                $categoryName = $item->product?->category?->name;
            }

            $addonsDisplay = collect($item->addons ?? [])->map(function (array $selection) use ($addonNames, $flavorProducts) {
                $addonName = $addonNames->get($selection['addon_id'], 'Adicional removido');
                $target = $selection['target'] !== null ? ($flavorProducts->get($selection['target'])?->name ?? 'sabor removido') : 'produto inteiro';

                return "{$selection['quantity']}x {$addonName} ({$target})";
            })->all();

            $giftsDisplay = collect($item->gifts ?? [])->map(function (array $gift) use ($giftNames) {
                $giftName = $giftNames->get($gift['gift_product_id'], 'Brinde removido');

                return ($gift['accepted'] ?? false) === true
                    ? "🎁 {$gift['quantity']}x {$giftName}"
                    : "🎁 {$giftName} — recusado pelo cliente";
            })->all();

            return [
                'name' => $name,
                'category_name' => $categoryName,
                'quantity' => $item->quantity,
                'note' => $item->note,
                'addons_display' => $addonsDisplay,
                'gifts_display' => $giftsDisplay,
                'line_total' => round(((float) $item->unit_price + (float) $item->addons_total) * $item->quantity, 2),
            ];
        })->values();
    }
}
