<?php

namespace App\Support\Sales;

use App\Models\Addon;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleOrderItemDiscount;
use App\Support\Fiscal\FlavorFraction;
use Illuminate\Support\Collection;

/**
 * Linhas do carrinho da tela Nova Venda: itens avulsos (editáveis) e itens de pedido lançados na
 * venda (só saem pela aba Pedidos). Monta nome, categoria e detalhes (sabores, adicionais) para a
 * tela — apresentação apenas; os valores continuam vindo das Actions de venda.
 *
 * @phpstan-type CartLine array{key: string, kind: 'standalone'|'order', id: int, name: string, category: ?string, flavors: list<string>, details: list<string>, quantity: int, editable: bool, gross_cents: int, discount_cents: int, total_cents: int, order_id: ?int}
 */
final class SaleCartLines
{
    /**
     * @return Collection<int, CartLine>
     */
    public static function for(Sale $sale): Collection
    {
        $standalone = SaleItem::query()->where('sale_id', $sale->id)->orderBy('id')->get()
            ->map(fn (SaleItem $item): array => [
                'key' => "s-{$item->id}",
                'kind' => 'standalone',
                'id' => $item->id,
                'name' => $item->product_name,
                'category' => null,
                'flavors' => [],
                'details' => [],
                'quantity' => (int) $item->quantity,
                'editable' => true,
                'gross_cents' => Money::toCents($item->total),
                'discount_cents' => min(Money::toCents($item->total), Money::toCents($item->discount_amount)),
                'total_cents' => Money::toCents($item->total) - min(Money::toCents($item->total), Money::toCents($item->discount_amount)),
                'order_id' => null,
            ]);

        $discounts = SaleOrderItemDiscount::query()->where('sale_id', $sale->id)->pluck('amount', 'order_item_id')
            ->map(fn ($amount): int => Money::toCents($amount))->all();
        $fromOrders = self::forOrderItems(OrderItem::query()->where('sale_id', $sale->id)->orderBy('order_id')->orderBy('id')->get(), $discounts);

        return $standalone->concat($fromOrders)->values();
    }

    /**
     * Linhas de itens de pedido (nome, categoria, sabores com fração e adicionais).
     *
     * @param  Collection<int, OrderItem>  $orderItems
     * @param  array<int, int>  $discounts  order_item_id => desconto da linha em centavos (Desc. R$)
     * @return Collection<int, CartLine>
     */
    public static function forOrderItems(Collection $orderItems, array $discounts = []): Collection
    {
        $names = self::names($orderItems);

        return $orderItems->map(function (OrderItem $item) use ($names, $discounts): array {
            $flavors = collect($item->flavors ?? [])->map(fn ($id): ?string => $names['products'][(int) $id] ?? null)->filter()->values();
            $primaryId = $flavors->isNotEmpty() ? (int) ($item->flavors[0] ?? 0) : (int) $item->product_id;
            $multi = $flavors->count() > 1;
            $name = $multi ? $flavors->implode(' / ') : ($names['products'][$primaryId] ?? 'Produto removido');
            $fraction = $multi ? FlavorFraction::label(100 / $flavors->count()) : null;
            $flavorLines = $multi ? $flavors->map(fn (string $flavor): string => $fraction.' '.$flavor)->all() : [];
            $details = [];

            foreach ($item->addons ?? [] as $selection) {
                $addon = $names['addons'][(int) ($selection['addon_id'] ?? 0)] ?? null;

                if ($addon !== null) {
                    $details[] = 'Adic. '.$addon;
                }
            }

            $quantity = (int) $item->quantity;
            $grossCents = (Money::toCents($item->unit_price) + Money::toCents($item->addons_total)) * $quantity;
            $discountCents = min($grossCents, $discounts[$item->id] ?? 0);

            return [
                'key' => "o-{$item->id}",
                'kind' => 'order',
                'id' => $item->id,
                'name' => $name,
                'category' => $names['categories'][$primaryId] ?? null,
                'flavors' => $flavorLines,
                'details' => $details,
                'quantity' => $quantity,
                'editable' => false,
                'gross_cents' => $grossCents,
                'discount_cents' => $discountCents,
                'total_cents' => $grossCents - $discountCents,
                'order_id' => (int) $item->order_id,
            ];
        })->values();
    }

    /**
     * Nome de produtos/sabores (com excluídos) e de adicionais, em consultas únicas.
     *
     * @param  Collection<int, OrderItem>  $items
     * @return array{products: array<int, string>, categories: array<int, ?string>, addons: array<int, string>}
     */
    public static function names(Collection $items): array
    {
        $productIds = $items->flatMap(fn (OrderItem $item): array => [$item->product_id, ...($item->flavors ?? [])])->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $addonIds = $items->flatMap(fn (OrderItem $item): array => array_column($item->addons ?? [], 'addon_id'))->filter()->map(fn ($id): int => (int) $id)->unique()->values();

        $products = $productIds->isEmpty() ? collect() : Product::query()->withTrashed()->with('category')->whereIn('id', $productIds)->get()->keyBy('id');

        return [
            'products' => $products->map(fn (Product $product): string => $product->name)->all(),
            'categories' => $products->map(fn (Product $product): ?string => $product->category?->name)->all(),
            'addons' => $addonIds->isEmpty() ? [] : Addon::query()->withTrashed()->whereIn('id', $addonIds)->pluck('name', 'id')->all(),
        ];
    }
}
