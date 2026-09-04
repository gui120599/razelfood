<?php

namespace App\Actions\Menu;

use App\Enums\GiftAwardMode;
use App\Models\Addon;
use App\Models\Category;
use App\Models\FlavorQuantityOption;
use App\Models\Product;
use App\Models\ProductAddon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Envolve ResolvePriceForProduct. Pra combo (sabores), o preço é a MÉDIA
 * dos preços resolvidos de cada sabor (RN-16 — é assim que se calcula
 * meio a meio: preço de UMA pizza, não a soma de N). Valida no servidor
 * (nunca confia no que o Livewire mandou) que os sabores pertencem à
 * mesma categoria com allows_flavors=true e que a quantidade bate com uma
 * das opções cadastradas em flavor_quantity_options para a categoria.
 */
class ResolvePriceForCartLine
{
    public function __construct(
        private readonly ResolvePriceForProduct $resolvePriceForProduct,
    ) {}

    /**
     * @param  array{type: string, product_id: int, flavor_ids: array<int>, quantity: int, note: ?string, addons?: array<int, array{addon_id:int, quantity:int, target:?int}>, gifts?: array<int, array{gift_product_id:int, accepted:bool}>}  $cartItem
     * @return array{unit_price: float, original_unit_price: float, flash_promotion_id: ?int, flavor_prices: array<int, ResolvedPrice>, flavor_shares: array<int, float>, addons_total: float, addons: array<int, array{addon_id:int, quantity:int, target:?int, target_share:float, unit_cost:float}>, gifts: array<int, array{gift_product_id:int, quantity:int, accepted:bool, award_mode:string}>}
     */
    public function __invoke(array $cartItem): array
    {
        if ($cartItem['type'] === 'combo') {
            return $this->resolveCombo($cartItem['flavor_ids'], $cartItem['addons'] ?? [], $cartItem['gifts'] ?? []);
        }

        $product = Product::findOrFail($cartItem['product_id']);
        $resolved = ($this->resolvePriceForProduct)($product);
        $addonsResolved = $this->resolveAddons($cartItem['addons'] ?? [], [], [], $product->id);
        $giftsResolved = $this->resolveGifts($cartItem['gifts'] ?? [], [$product->id], 1);

        return [
            'unit_price' => $resolved->finalPrice,
            'original_unit_price' => $resolved->originalPrice,
            'flash_promotion_id' => $resolved->matchedFlashPromotionId,
            'flavor_prices' => [],
            'flavor_shares' => [],
            'addons_total' => $addonsResolved['addons_total'],
            'addons' => $addonsResolved['addon_lines'],
            'gifts' => $giftsResolved['gifts'],
        ];
    }

    /**
     * @param  array<int>  $flavorIds
     * @param  array<int, array{addon_id:int, quantity:int, target:?int}>  $addonSelections
     * @param  array<int, array{gift_product_id:int, accepted:bool}>  $giftSelections
     * @return array{unit_price: float, original_unit_price: float, flash_promotion_id: ?int, flavor_prices: array<int, ResolvedPrice>, flavor_shares: array<int, float>, addons_total: float, addons: array<int, array{addon_id:int, quantity:int, target:?int, target_share:float, unit_cost:float}>, gifts: array<int, array{gift_product_id:int, quantity:int, accepted:bool, award_mode:string}>}
     */
    private function resolveCombo(array $flavorIds, array $addonSelections = [], array $giftSelections = []): array
    {
        $flavorIds = array_values(array_unique($flavorIds));

        if (count($flavorIds) < 2) {
            throw new InvalidArgumentException('Um combo precisa de pelo menos 2 sabores.');
        }

        $products = Product::whereIn('id', $flavorIds)->get()->keyBy('id');

        if ($products->count() !== count($flavorIds)) {
            throw new InvalidArgumentException('Um ou mais sabores não foram encontrados.');
        }

        $categoryIds = $products->pluck('category_id')->unique();

        if ($categoryIds->count() > 1) {
            throw new InvalidArgumentException('Os sabores precisam ser da mesma categoria.');
        }

        $category = Category::with(['flavorQuantityOptions', 'parent.flavorQuantityOptions'])
            ->findOrFail($categoryIds->first());

        if (! $category->allows_flavors) {
            throw new InvalidArgumentException('Esta categoria não permite combinar sabores.');
        }

        $quantityOption = $category->resolvedFlavorQuantityOptions()->firstWhere('flavor_count', count($flavorIds));

        if (! $quantityOption) {
            throw new InvalidArgumentException('Esta categoria (ou a categoria pai, se estiver herdando) não tem uma opção configurada para essa quantidade de sabores.');
        }

        $prices = collect($flavorIds)->map(
            fn (int $id) => ($this->resolvePriceForProduct)($products->get($id))
        );

        $this->assertFlavorsAllowedByPromotions($prices, $products, $flavorIds);

        // Sem flash_promotion_id único de verdade quando os sabores vêm de
        // promoções diferentes — grava a do sabor que também é o
        // product_id da linha (o primeiro), só como dica de auditoria; a
        // baixa de saldo real acontece por sabor, não por essa coluna.
        $firstFlashPromotionId = $prices->first()->matchedFlashPromotionId;

        // % configurado por posição na opção de quantidade (Admin decide o
        // rateio, ex.: 33/33/34 pra 3 sabores) — nunca dividido igualmente
        // em código, pra não reintroduzir o resíduo de arredondamento.
        $shares = collect($quantityOption->flavor_shares ?? FlavorQuantityOption::equalShares(count($flavorIds)))
            ->map(fn ($share) => $share / 100)
            ->values()
            ->all();

        $addonsResolved = $this->resolveAddons($addonSelections, $flavorIds, $shares, $flavorIds[0]);
        $giftsResolved = $this->resolveGifts($giftSelections, $flavorIds, count($flavorIds));

        return [
            'unit_price' => round($prices->avg('finalPrice'), 2),
            'original_unit_price' => round($prices->avg('originalPrice'), 2),
            'flash_promotion_id' => $firstFlashPromotionId,
            'flavor_prices' => $prices->values()->all(),
            'flavor_shares' => $shares,
            'addons_total' => $addonsResolved['addons_total'],
            'addons' => $addonsResolved['addon_lines'],
            'gifts' => $giftsResolved['gifts'],
        ];
    }

    /**
     * Resolve os adicionais de uma linha (produto simples ou combo). Nunca
     * confia em nome/preço vindo do cliente — só {addon_id, quantity,
     * target} — e reaproveita o MESMO $flavorShares já calculado por
     * resolveCombo() pra derivar o target_share, nunca recalculando um
     * rateio paralelo (RN-48).
     *
     * @param  array<int, array{addon_id:int, quantity:int, target:?int}>  $selections
     * @param  array<int>  $flavorIds  vazio para produto simples
     * @param  array<int, float>  $flavorShares  vazio para produto simples, paralelo a $flavorIds
     * @param  int  $anchorProductId  produto simples, ou flavor_ids[0] no combo (mesma âncora de product_id)
     * @return array{addons_total: float, addon_lines: array<int, array{addon_id:int, quantity:int, target:?int, target_share:float, unit_cost:float}>}
     */
    private function resolveAddons(array $selections, array $flavorIds, array $flavorShares, int $anchorProductId): array
    {
        if (empty($selections)) {
            return ['addons_total' => 0.0, 'addon_lines' => []];
        }

        $addons = Addon::whereIn('id', collect($selections)->pluck('addon_id')->unique())->get()->keyBy('id');
        $addonLines = [];
        $total = 0.0;

        foreach ($selections as $selection) {
            $addon = $addons->get($selection['addon_id'])
                ?? throw new InvalidArgumentException('Adicional não encontrado.');
            $quantity = max(1, (int) $selection['quantity']);
            $target = $selection['target'] ?? null;

            if ($target !== null && ! in_array($target, $flavorIds, true)) {
                throw new InvalidArgumentException('O sabor alvo do adicional não faz parte deste item.');
            }

            if ($target === null && count($flavorIds) > 1) {
                // Combo, alvo = produto inteiro: exige o adicional disponível
                // em TODOS os sabores escolhidos (mesmo padrão de
                // assertFlavorsAllowedByPromotions — "todo sabor precisa
                // satisfazer X"). O preço/teto efetivo vem do pivot do sabor
                // âncora (flavor_ids[0]) — mesma âncora já usada pro
                // product_id e pro flash_promotion_id de auditoria do combo.
                $pivots = ProductAddon::where('addon_id', $addon->id)->whereIn('product_id', $flavorIds)->get()->keyBy('product_id');

                if ($pivots->count() !== count($flavorIds)) {
                    throw new InvalidArgumentException("O adicional \"{$addon->name}\" não está disponível para todos os sabores deste combo.");
                }

                $pivot = $pivots->get($anchorProductId);
                $targetShare = 1.0;
            } else {
                $lookupProductId = $target ?? $anchorProductId;
                $pivot = ProductAddon::where('addon_id', $addon->id)->where('product_id', $lookupProductId)->first()
                    ?? throw new InvalidArgumentException("O adicional \"{$addon->name}\" não está disponível para este produto.");

                $targetShare = $target === null ? 1.0 : $flavorShares[array_search($target, $flavorIds, true)];
            }

            if ($pivot->max_quantity !== null && $quantity > $pivot->max_quantity) {
                throw new InvalidArgumentException("Máximo de {$pivot->max_quantity} unidade(s) do adicional \"{$addon->name}\".");
            }

            $effectivePrice = (float) ($pivot->price ?? $addon->price);
            $unitCost = round($effectivePrice * $quantity * $targetShare, 2);

            $addonLines[] = [
                'addon_id' => $addon->id,
                'quantity' => $quantity,
                'target' => $target,
                'target_share' => $targetShare,
                'unit_cost' => $unitCost,
            ];
            $total += $unitCost;
        }

        return ['addons_total' => round($total, 2), 'addon_lines' => $addonLines];
    }

    /**
     * Resolve os brindes de uma linha (RN-53). A fonte da verdade é SEMPRE o
     * servidor: itera sobre os vínculos `product_gift` ativos dos produtos-âncora
     * (produto simples, ou cada sabor do combo) e só consulta a seleção do
     * cliente como um lookup booleano de aceite. Um `gift_product_id` forjado,
     * não vinculado, inativo, de si mesmo, ou de uma quantidade de sabores não
     * habilitada é simplesmente ignorado (não lança — não deve travar o
     * checkout do cliente). O brinde NUNCA tem preço: não entra em unit_price,
     * addons_total nem no desconto.
     *
     * @param  array<int, array{gift_product_id:int, accepted:bool}>  $giftSelections
     * @param  array<int>  $anchorProductIds  [product_id] no simples; flavor_ids no combo
     * @param  int  $flavorCount  1 no produto simples; count($flavorIds) no combo
     * @return array{gifts: array<int, array{gift_product_id:int, quantity:int, accepted:bool, award_mode:string}>}
     */
    private function resolveGifts(array $giftSelections, array $anchorProductIds, int $flavorCount): array
    {
        $acceptedIds = collect($giftSelections)
            ->filter(fn ($selection) => ($selection['accepted'] ?? false) === true)
            ->pluck('gift_product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Via a relação gifts() (escopada por tenant + exclui produto-brinde
        // soft-deleted). No máximo 4 produtos-âncora (combo de até 4 sabores).
        $anchors = Product::whereIn('id', $anchorProductIds)
            ->with(['gifts' => fn ($query) => $query->wherePivot('is_active', true)])
            ->get();

        $lines = $anchors
            ->flatMap(fn (Product $anchor) => $anchor->gifts)
            ->filter(function (Product $gift) use ($flavorCount, $anchorProductIds) {
                if (in_array($gift->id, $anchorProductIds, true)) {
                    return false; // um produto não é brinde de si mesmo
                }

                $counts = $gift->pivot->flavor_counts;

                return empty($counts) || in_array($flavorCount, array_map('intval', $counts), true);
            })
            ->groupBy('id')
            ->map(fn (Collection $group) => [
                'gift_product_id' => (int) $group->first()->id,
                'quantity' => max(1, (int) $group->max(fn (Product $gift) => $gift->pivot->quantity)),
                'accepted' => in_array((int) $group->first()->id, $acceptedIds, true),
                // Conflito entre vínculos do mesmo brinde (2 sabores de um combo):
                // per_quantity vence — é a contagem física mais segura.
                'award_mode' => $group->contains(fn (Product $gift) => $gift->pivot->award_mode === GiftAwardMode::PerQuantity)
                    ? GiftAwardMode::PerQuantity->value
                    : GiftAwardMode::PerOrder->value,
            ])
            ->values()
            ->all();

        return ['gifts' => $lines];
    }

    /**
     * Um sabor cuja promoção relâmpago vigente está marcada como "não
     * permite sabores" só pode ser vendido inteiro — nunca dentro de um
     * combo, mesmo que a categoria permita. Se a promoção permite sabores
     * mas define um teto próprio, esse teto prevalece sobre o da categoria
     * (é sempre o mais restritivo que vale).
     *
     * @param  Collection<int, ResolvedPrice>  $prices
     * @param  Collection<int, Product>  $products
     * @param  array<int>  $flavorIds
     */
    private function assertFlavorsAllowedByPromotions(Collection $prices, Collection $products, array $flavorIds): void
    {
        $tightestMax = null;

        foreach ($prices as $index => $price) {
            $promotion = $price->matchedFlashPromotion;

            if ($promotion === null) {
                continue;
            }

            if (! $promotion->allows_flavors) {
                $productName = $products->get($flavorIds[$index])->name;

                throw new InvalidArgumentException("O produto \"{$productName}\" está em promoção só para unidade inteira e não pode ser combinado com outros sabores.");
            }

            if ($promotion->max_flavors !== null) {
                $tightestMax = $tightestMax === null ? $promotion->max_flavors : min($tightestMax, $promotion->max_flavors);
            }
        }

        if ($tightestMax !== null && count($flavorIds) > $tightestMax) {
            throw new InvalidArgumentException("Uma promoção do seu combo permite no máximo {$tightestMax} sabores.");
        }
    }
}
