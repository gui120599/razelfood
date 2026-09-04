<?php

namespace App\Actions\Orders\Support;

use App\Enums\FlashPromotionStatus;
use App\Exceptions\CheckoutException;
use App\Models\Addon;
use App\Models\FlashPromotion;
use App\Models\FlashPromotionProduct;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Lock/validação/consumo de estoque e saldo de promoção relâmpago,
 * extraído de CreateOrderFromCart para ser reaproveitado também por
 * UpdateOrderFromCart (que precisa da mesma disciplina de lock para
 * reverter o consumo dos itens antigos de um pedido antes de aplicar os
 * novos). Trava SEMPRE na mesma ordem entre tabelas — flash_promotions →
 * flash_promotion_products → products → addons, com uma query por tabela
 * (whereIn + orderBy(id) + lockForUpdate), nunca lock por linha em loop —
 * evita deadlock entre transações concorrentes que referenciam as mesmas
 * promoções/produtos/adicionais.
 */
class CartStockAndPromotionLedger
{
    /**
     * @param  Collection<int, array{item: array, resolved: array}>  $resolvedLines
     * @return array{0: array<string, int>, 1: array<int, float>, 2: array<int, float>, 3: array<int, float>}
     */
    public function buildConsumptionMaps(Collection $resolvedLines): array
    {
        $promoConsumption = [];     // "{promoId}:{productId}" => qty
        $stockConsumption = [];     // productId => qty
        $addonConsumption = [];     // addonId => qty (fração, via target_share)
        $giftSalesExclusion = [];   // productId => qty de brinde (some de sales_count, NÃO de stock_quantity — RN-53 decisão #3)
        $perOrderGiftUnits = [];    // productId => qty de brinde "por pedido" (RN-53) — somada UMA vez, depois do loop

        foreach ($resolvedLines as $line) {
            $quantity = $line['item']['quantity'];

            foreach ($line['resolved']['addons'] ?? [] as $addonLine) {
                $addonConsumption[$addonLine['addon_id']] = ($addonConsumption[$addonLine['addon_id']] ?? 0)
                    + ($quantity * $addonLine['quantity'] * $addonLine['target_share']);
            }

            // Brinde aceito (RN-53): sai do estoque físico pelo mesmo fluxo dos
            // demais produtos (entra em $stockConsumption → lock + assert +
            // débito de stock_quantity), mas é registrado em $giftSalesExclusion
            // para NÃO inflar sales_count / "mais vendidos" (RN-15). O modo
            // `per_order` sai uma única vez no pedido inteiro (acumulado à parte
            // e somado depois do loop, pegando a maior quantidade se o mesmo
            // brinde vier de vínculos diferentes).
            foreach ($line['resolved']['gifts'] ?? [] as $giftLine) {
                if (($giftLine['accepted'] ?? false) !== true) {
                    continue;
                }

                $giftProductId = $giftLine['gift_product_id'];

                if (($giftLine['award_mode'] ?? 'per_quantity') === 'per_order') {
                    $perOrderGiftUnits[$giftProductId] = max($perOrderGiftUnits[$giftProductId] ?? 0, $giftLine['quantity']);

                    continue;
                }

                $giftUnits = $quantity * $giftLine['quantity'];
                $stockConsumption[$giftProductId] = ($stockConsumption[$giftProductId] ?? 0) + $giftUnits;
                $giftSalesExclusion[$giftProductId] = ($giftSalesExclusion[$giftProductId] ?? 0) + $giftUnits;
            }

            if ($line['item']['type'] === 'combo') {
                // Estoque/vendagem são rateados entre os sabores do combo pelo
                // % configurado na opção de quantidade (FlavorQuantityOption,
                // Admin decide — ex.: 33/33/34 pra 3 sabores, soma sempre
                // 100%), não por divisão igualitária em código — isso evita
                // resíduo de arredondamento (1/3 + 1/3 + 1/3 nunca fecha 100%
                // em decimal(10,2)). Diferente do saldo de promoção relâmpago
                // abaixo, que continua debitando a unidade cheia por sabor.
                foreach ($line['item']['flavor_ids'] as $index => $flavorId) {
                    $flavorPrice = $line['resolved']['flavor_prices'][$index];
                    $share = $line['resolved']['flavor_shares'][$index];
                    $stockConsumption[$flavorId] = ($stockConsumption[$flavorId] ?? 0) + ($quantity * $share);

                    if ($flavorPrice->matchedFlashPromotionId) {
                        $key = "{$flavorPrice->matchedFlashPromotionId}:{$flavorId}";
                        $promoConsumption[$key] = ($promoConsumption[$key] ?? 0) + $quantity;
                    }
                }

                continue;
            }

            $productId = $line['item']['product_id'];
            $stockConsumption[$productId] = ($stockConsumption[$productId] ?? 0) + $quantity;

            if ($line['resolved']['flash_promotion_id']) {
                $key = "{$line['resolved']['flash_promotion_id']}:{$productId}";
                $promoConsumption[$key] = ($promoConsumption[$key] ?? 0) + $quantity;
            }
        }

        // Brinde "por pedido" (RN-53): somado uma única vez, independente de
        // quantas linhas/unidades o dispararam.
        foreach ($perOrderGiftUnits as $giftProductId => $units) {
            $stockConsumption[$giftProductId] = ($stockConsumption[$giftProductId] ?? 0) + $units;
            $giftSalesExclusion[$giftProductId] = ($giftSalesExclusion[$giftProductId] ?? 0) + $units;
        }

        return [$promoConsumption, $stockConsumption, $addonConsumption, $giftSalesExclusion];
    }

    /**
     * @param  array<string, int>  $promoConsumption
     * @return Collection<int, FlashPromotion>
     */
    public function lockFlashPromotions(array $promoConsumption): Collection
    {
        $promotionIds = collect(array_keys($promoConsumption))
            ->map(fn (string $key) => (int) explode(':', $key)[0])
            ->unique()
            ->sort()
            ->values();

        if ($promotionIds->isEmpty()) {
            return collect();
        }

        return FlashPromotion::whereIn('id', $promotionIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, FlashPromotion>  $promotions
     */
    public function resetRecurringPromotionsIfNeeded(Collection $promotions): void
    {
        foreach ($promotions as $promotion) {
            if ($promotion->needsRecurringReset()) {
                $promotion->update(['sold_quantity' => 0, 'last_reset_at' => now()]);
            }
        }
    }

    /**
     * @param  Collection<int, FlashPromotion>  $promotions
     */
    public function assertPromotionsStillActive(Collection $promotions): void
    {
        foreach ($promotions as $promotion) {
            if ($promotion->computedStatus() !== FlashPromotionStatus::Active) {
                throw new CheckoutException('Uma promoção do pedido não está mais disponível. Atualize a página e tente de novo.');
            }
        }
    }

    /**
     * @param  array<string, int>  $promoConsumption
     * @return Collection<string, FlashPromotionProduct>
     */
    public function lockFlashPromotionProducts(array $promoConsumption): Collection
    {
        if (empty($promoConsumption)) {
            return collect();
        }

        $pairs = collect(array_keys($promoConsumption))->map(function (string $key) {
            [$promoId, $productId] = array_map('intval', explode(':', $key));

            return compact('promoId', 'productId');
        });

        return FlashPromotionProduct::query()
            ->where(function ($query) use ($pairs) {
                foreach ($pairs as $pair) {
                    $query->orWhere(fn ($q) => $q
                        ->where('flash_promotion_id', $pair['promoId'])
                        ->where('product_id', $pair['productId']));
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (FlashPromotionProduct $pivot) => "{$pivot->flash_promotion_id}:{$pivot->product_id}");
    }

    /**
     * @param  array<string, int>  $promoConsumption
     * @param  Collection<string, FlashPromotionProduct>  $pivots
     * @param  Collection<int, FlashPromotion>  $promotions
     * @return array<int, int> promoId => quantidade total consumida
     */
    public function assertPivotAndPerOrderLimits(array $promoConsumption, Collection $pivots, Collection $promotions): array
    {
        $promoTotalConsumption = [];

        foreach ($promoConsumption as $key => $quantity) {
            [$promoId] = array_map('intval', explode(':', $key));
            $promoTotalConsumption[$promoId] = ($promoTotalConsumption[$promoId] ?? 0) + $quantity;

            $pivot = $pivots->get($key);

            if ($pivot && $pivot->total_quantity !== null && ($pivot->sold_quantity + $quantity) > $pivot->total_quantity) {
                throw new CheckoutException('Uma promoção esgotou enquanto o pedido era montado.');
            }
        }

        foreach ($promoTotalConsumption as $promoId => $quantity) {
            $promotion = $promotions->get($promoId);

            if ($promotion->per_order_limit !== null && $quantity > $promotion->per_order_limit) {
                throw new CheckoutException("Limite de {$promotion->per_order_limit} unidade(s) por pedido para a promoção \"{$promotion->name}\".");
            }

            if ($promotion->total_quantity !== null && ($promotion->sold_quantity + $quantity) > $promotion->total_quantity) {
                throw new CheckoutException('Uma promoção esgotou enquanto o pedido era montado.');
            }
        }

        return $promoTotalConsumption;
    }

    /**
     * @param  array<int, float>  $stockConsumption
     * @return Collection<int, Product>
     */
    public function lockStockControlledProducts(array $stockConsumption): Collection
    {
        $productIds = collect(array_keys($stockConsumption))->sort()->values();

        if ($productIds->isEmpty()) {
            return collect();
        }

        return Product::whereIn('id', $productIds)
            ->where('controls_stock', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, Product>  $stockControlledProducts
     * @param  array<int, float>  $stockConsumption
     */
    public function assertStockAvailable(Collection $stockControlledProducts, array $stockConsumption): void
    {
        foreach ($stockControlledProducts as $product) {
            $needed = $stockConsumption[$product->id];

            if ($product->stock_quantity < $needed) {
                throw new CheckoutException("O produto \"{$product->name}\" não tem estoque suficiente.");
            }
        }
    }

    /**
     * @param  array<int, float>  $addonConsumption
     * @return Collection<int, Addon>
     */
    public function lockStockControlledAddons(array $addonConsumption): Collection
    {
        $addonIds = collect(array_keys($addonConsumption))->sort()->values();

        if ($addonIds->isEmpty()) {
            return collect();
        }

        return Addon::whereIn('id', $addonIds)
            ->where('controls_stock', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, Addon>  $stockControlledAddons
     * @param  array<int, float>  $addonConsumption
     */
    public function assertAddonStockAvailable(Collection $stockControlledAddons, array $addonConsumption): void
    {
        foreach ($stockControlledAddons as $addon) {
            $needed = $addonConsumption[$addon->id];

            if ($addon->stock_quantity < $needed) {
                throw new CheckoutException("O adicional \"{$addon->name}\" não tem estoque suficiente.");
            }
        }
    }

    /**
     * Debita saldo de promoção/estoque e soma sales_count — usado ao criar
     * um pedido ou ao aplicar os itens novos de uma edição.
     *
     * @param  Collection<int, FlashPromotion>  $promotions
     * @param  array<int, int>  $promoTotalConsumption
     * @param  Collection<string, FlashPromotionProduct>  $pivots
     * @param  array<string, int>  $promoConsumption
     * @param  Collection<int, Product>  $stockControlledProducts
     * @param  array<int, float>  $stockConsumption
     * @param  Collection<int, Addon>  $stockControlledAddons
     * @param  array<int, float>  $addonConsumption
     * @param  array<int, float>  $giftSalesExclusion  unidades de brinde a NÃO contar em sales_count (RN-53)
     */
    public function applyDecrements(
        Collection $promotions,
        array $promoTotalConsumption,
        Collection $pivots,
        array $promoConsumption,
        Collection $stockControlledProducts,
        array $stockConsumption,
        Collection $stockControlledAddons,
        array $addonConsumption,
        array $giftSalesExclusion = [],
    ): void {
        foreach ($promoTotalConsumption as $promoId => $quantity) {
            $promotions->get($promoId)->increment('sold_quantity', $quantity);
        }

        foreach ($promoConsumption as $key => $quantity) {
            $pivots->get($key)?->increment('sold_quantity', $quantity);
        }

        foreach ($stockControlledProducts as $product) {
            $product->decrement('stock_quantity', $stockConsumption[$product->id]);
        }

        // sales_count (RN-15) incrementa pra TODOS os produtos vendidos, não só
        // os com controle de estoque. As unidades entregues como brinde (RN-53)
        // são descontadas — brinde grátis move estoque mas não vira "mais vendido".
        foreach ($stockConsumption as $productId => $quantity) {
            $net = $quantity - ($giftSalesExclusion[$productId] ?? 0);

            if (abs($net) > 1e-9) {
                Product::where('id', $productId)->increment('sales_count', $net);
            }
        }

        foreach ($stockControlledAddons as $addon) {
            $addon->decrement('stock_quantity', $addonConsumption[$addon->id]);
        }

        // sales_count do adicional (mesmo raciocínio de RN-15) incrementa pra
        // TODOS os adicionais vendidos, não só os com controle de estoque.
        foreach ($addonConsumption as $addonId => $quantity) {
            Addon::where('id', $addonId)->increment('sales_count', $quantity);
        }
    }

    /**
     * Espelho de applyDecrements (sinal invertido) — usado por
     * UpdateOrderFromCart para reverter o consumo dos itens ANTIGOS de um
     * pedido antes de validar/aplicar os itens novos. Não valida limites
     * (reverter saldo nunca deveria estourar um teto), só devolve.
     *
     * @param  Collection<int, FlashPromotion>  $promotions
     * @param  array<int, int>  $promoTotalConsumption
     * @param  Collection<string, FlashPromotionProduct>  $pivots
     * @param  array<string, int>  $promoConsumption
     * @param  Collection<int, Product>  $stockControlledProducts
     * @param  array<int, float>  $stockConsumption
     * @param  Collection<int, Addon>  $stockControlledAddons
     * @param  array<int, float>  $addonConsumption
     * @param  array<int, float>  $giftSalesExclusion  unidades de brinde que não foram contadas em sales_count (RN-53)
     */
    public function applyIncrements(
        Collection $promotions,
        array $promoTotalConsumption,
        Collection $pivots,
        array $promoConsumption,
        Collection $stockControlledProducts,
        array $stockConsumption,
        Collection $stockControlledAddons,
        array $addonConsumption,
        array $giftSalesExclusion = [],
    ): void {
        foreach ($promoTotalConsumption as $promoId => $quantity) {
            $promotions->get($promoId)?->decrement('sold_quantity', $quantity);
        }

        foreach ($promoConsumption as $key => $quantity) {
            $pivots->get($key)?->decrement('sold_quantity', $quantity);
        }

        foreach ($stockControlledProducts as $product) {
            if (isset($stockConsumption[$product->id])) {
                $product->increment('stock_quantity', $stockConsumption[$product->id]);
            }
        }

        foreach ($stockConsumption as $productId => $quantity) {
            $net = $quantity - ($giftSalesExclusion[$productId] ?? 0);

            if (abs($net) > 1e-9) {
                Product::where('id', $productId)->decrement('sales_count', $net);
            }
        }

        foreach ($stockControlledAddons as $addon) {
            if (isset($addonConsumption[$addon->id])) {
                $addon->increment('stock_quantity', $addonConsumption[$addon->id]);
            }
        }

        foreach ($addonConsumption as $addonId => $quantity) {
            Addon::where('id', $addonId)->decrement('sales_count', $quantity);
        }
    }
}
