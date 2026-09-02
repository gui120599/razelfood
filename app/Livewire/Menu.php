<?php

namespace App\Livewire;

use App\Actions\Menu\CheckBusinessHours;
use App\Actions\Menu\ResolvePriceForCartLine;
use App\Actions\Menu\ResolvePriceForProduct;
use App\Enums\FlashPromotionStatus;
use App\Livewire\Concerns\EstablishesTenantContext;
use App\Models\Addon;
use App\Models\Category;
use App\Models\FlashPromotion;
use App\Models\Product;
use App\Support\Cart;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class Menu extends Component
{
    use EstablishesTenantContext;

    /** @var array{category_id: ?int, quantity_option_id: ?int, required_count: ?int, flavor_ids: array<int>, step: string} */
    public array $comboBuilder = [
        'category_id' => null,
        'quantity_option_id' => null,
        'required_count' => null,
        'flavor_ids' => [],
        'step' => 'flavors',
    ];

    /** @var array<int, array{quantity: int, target: ?int}> keyed por addon_id */
    public array $addonSelections = [];

    /**
     * null = ainda não perguntado; true = cliente quer escolher adicionais
     * pro combo (mostra a lista); false = optou por não adicionar nada
     * (finaliza o combo sem adicionais direto). Só se aplica ao sub-passo
     * `comboBuilder['step'] === 'addons'` — a visualização rápida de
     * produto simples (viewFromProduct) não força esse passo, o cliente já
     * decide não mexer na quantidade e clicar direto em "Adicionar".
     */
    public ?bool $comboAddonsGate = null;

    public bool $showCart = false;

    public ?int $viewingProductId = null;

    /**
     * Busca textual por nome de produto no cardápio público (RF-10). Só
     * filtra a partir de 2 caracteres; com o campo preenchido, a página
     * troca as seções normais (mais vendidos, promoções, categorias) pela
     * grade de resultados.
     */
    #[Url(as: 'q')]
    public string $search = '';

    /**
     * @param  array<int, array{addon_id:int, quantity:int, target:?int}>  $addons
     */
    public function addToCart(int $productId, array $addons = []): void
    {
        if (! $this->businessHours->isOpen) {
            $this->showCart = true;

            return;
        }

        Cart::addSimple($productId, addons: $addons);
        $this->showCart = true;
        $this->addonSelections = [];
        unset($this->cartLines);
    }

    public function viewProduct(int $productId): void
    {
        $this->viewingProductId = $productId;
        $this->addonSelections = [];
    }

    public function closeProductView(): void
    {
        $this->viewingProductId = null;
        $this->addonSelections = [];
    }

    public function addFromView(): void
    {
        if ($this->viewingProductId === null) {
            return;
        }

        $this->addToCart($this->viewingProductId, $this->addonSelectionsArray());
        $this->viewingProductId = null;
    }

    public function setAddonQuantity(int $addonId, int $quantity): void
    {
        if ($quantity <= 0) {
            unset($this->addonSelections[$addonId]);

            return;
        }

        $this->addonSelections[$addonId] = [
            'quantity' => $quantity,
            'target' => $this->addonSelections[$addonId]['target'] ?? null,
        ];
    }

    public function setAddonTarget(int $addonId, ?int $target): void
    {
        if (! isset($this->addonSelections[$addonId])) {
            return;
        }

        $this->addonSelections[$addonId]['target'] = $target;
    }

    /**
     * @return array<int, array{addon_id:int, quantity:int, target:?int}>
     */
    private function addonSelectionsArray(): array
    {
        return collect($this->addonSelections)
            ->map(fn (array $selection, int $addonId) => ['addon_id' => $addonId, 'quantity' => $selection['quantity'], 'target' => $selection['target']])
            ->values()
            ->all();
    }

    public function clearCart(): void
    {
        Cart::clear();
        unset($this->cartLines);
    }

    public function updateNote(int $index, string $note): void
    {
        Cart::updateNote($index, $note);
        unset($this->cartLines);
    }

    /**
     * Modal único de sabores (quantidade + lista, sem etapas): quando aberto
     * a partir do "+" de um produto específico, esse produto já entra
     * pré-selecionado na quantidade padrão (menor flavor_count cadastrada),
     * pra o cliente só complementar ou trocar — nunca escolher do zero de
     * novo algo que ele já tinha clicado.
     */
    public function startCombo(int $categoryId, ?int $productId = null): void
    {
        if (! $this->businessHours->isOpen) {
            $this->showCart = true;

            return;
        }

        $category = $this->menuCategory($categoryId);
        $defaultOption = $category?->resolvedFlavorQuantityOptions()->first();

        $this->comboBuilder = [
            'category_id' => $categoryId,
            'quantity_option_id' => $defaultOption?->id,
            'required_count' => $defaultOption?->flavor_count,
            'flavor_ids' => $productId ? [$productId] : [],
            'step' => 'flavors',
        ];
        $this->addonSelections = [];
        $this->comboAddonsGate = null;
        $this->viewingProductId = null;
    }

    /**
     * Troca a quantidade de sabores sem perder o que já estava selecionado
     * — só corta o excedente se a nova quantidade for menor (mantém os
     * primeiros escolhidos, incluindo o produto pré-selecionado).
     */
    public function selectFlavorQuantity(int $optionId): void
    {
        $category = $this->menuCategory($this->comboBuilder['category_id']);
        $option = $category?->resolvedFlavorQuantityOptions()->firstWhere('id', $optionId);

        if (! $option) {
            return;
        }

        $this->comboBuilder['quantity_option_id'] = $option->id;
        $this->comboBuilder['required_count'] = $option->flavor_count;
        $this->comboBuilder['flavor_ids'] = array_slice($this->comboBuilder['flavor_ids'], 0, $option->flavor_count);
    }

    public function toggleFlavor(int $productId): void
    {
        $flavorIds = $this->comboBuilder['flavor_ids'];
        $requiredCount = $this->comboBuilder['required_count'];

        if (in_array($productId, $flavorIds, true)) {
            $this->comboBuilder['flavor_ids'] = array_values(array_diff($flavorIds, [$productId]));

            return;
        }

        if ($requiredCount === 1) {
            $this->comboBuilder['flavor_ids'] = [$productId];

            return;
        }

        if ($requiredCount !== null && count($flavorIds) >= $requiredCount) {
            return;
        }

        $this->comboBuilder['flavor_ids'][] = $productId;
    }

    /**
     * Confirma a seleção de sabores. Se algum sabor escolhido tiver
     * adicionais anexados, NÃO adiciona ainda — muda pro sub-passo
     * `step: 'addons'` dentro do MESMO modal (preserva a disciplina de
     * "modal único, sem etapas separadas" — ver .ai/rules/menu.md — só
     * adiciona um sub-passo interno, nunca um modal novo). Sem adicionais
     * disponíveis, comportamento idêntico ao anterior (adiciona direto).
     */
    public function confirmCombo(): void
    {
        $requiredCount = $this->comboBuilder['required_count'];

        if ($requiredCount === null || count($this->comboBuilder['flavor_ids']) !== $requiredCount) {
            return;
        }

        // Defesa extra: turno pode ter fechado enquanto o modal estava aberto.
        if (! $this->businessHours->isOpen) {
            $this->cancelCombo();
            $this->showCart = true;

            return;
        }

        if ($this->comboAddons->isNotEmpty()) {
            $this->comboBuilder['step'] = 'addons';

            return;
        }

        $this->finalizeCombo();
    }

    public function chooseComboWantsAddons(bool $wantsAddons): void
    {
        $this->comboAddonsGate = $wantsAddons;

        if (! $wantsAddons) {
            $this->confirmComboAddons();
        }
    }

    public function confirmComboAddons(): void
    {
        $this->finalizeCombo();
    }

    /**
     * Atalho pra quando o cliente já está vendo a lista mas decide não
     * levar nenhum adicional (achou caro, não gostou das opções etc.) —
     * limpa qualquer seleção parcial e finaliza o combo sem adicionais,
     * sem precisar zerar quantidade item por item.
     */
    public function skipComboAddons(): void
    {
        $this->addonSelections = [];
        $this->finalizeCombo();
    }

    private function finalizeCombo(): void
    {
        $flavorIds = $this->comboBuilder['flavor_ids'];
        $addons = $this->addonSelectionsArray();

        if ($this->comboBuilder['required_count'] === 1) {
            Cart::addSimple($flavorIds[0], addons: $addons);
        } else {
            Cart::addCombo($flavorIds, addons: $addons);
        }

        $this->cancelCombo();
        $this->showCart = true;
        unset($this->cartLines);
    }

    public function cancelCombo(): void
    {
        $this->comboBuilder = [
            'category_id' => null,
            'quantity_option_id' => null,
            'required_count' => null,
            'flavor_ids' => [],
            'step' => 'flavors',
        ];
        $this->addonSelections = [];
        $this->comboAddonsGate = null;
    }

    public function removeFromCart(int $index): void
    {
        Cart::remove($index);
        unset($this->cartLines);
    }

    public function updateQuantity(int $index, int $quantity): void
    {
        Cart::updateQuantity($index, $quantity);
        unset($this->cartLines);
    }

    /**
     * Adicionais disponíveis pros sabores em montagem no combo — cada um
     * com a lista de produtos (sabores) a que está de fato anexado, pra
     * montar o seletor de alvo (RN-48).
     *
     * @return Collection<int, Addon>
     */
    #[Computed]
    public function comboAddons(): Collection
    {
        $flavorIds = $this->comboBuilder['flavor_ids'];

        if (empty($flavorIds)) {
            return collect();
        }

        return Addon::whereHas('products', fn ($query) => $query->whereIn('products.id', $flavorIds))
            ->with(['products' => fn ($query) => $query->whereIn('products.id', $flavorIds)])
            ->orderBy('display_order')
            ->get();
    }

    /**
     * @return Collection<int, Addon>
     */
    #[Computed]
    public function viewingProductAddons(): Collection
    {
        if ($this->viewingProductId === null) {
            return collect();
        }

        return Addon::whereHas('products', fn ($query) => $query->where('products.id', $this->viewingProductId))
            ->orderBy('display_order')
            ->get();
    }

    public function comboAllowsWholeProduct(Addon $addon): bool
    {
        $flavorIds = $this->comboBuilder['flavor_ids'];

        if (count($flavorIds) <= 1) {
            return true;
        }

        return $addon->products->pluck('id')->intersect($flavorIds)->count() === count($flavorIds);
    }

    /**
     * Com um único sabor selecionado não faz sentido perguntar "produto
     * inteiro ou só esse sabor" — só existe um sabor possível, então nem
     * a lista de alvos por sabor é montada (fica pro Blade decidir não
     * mostrar o seletor nesse caso).
     *
     * @return Collection<int, Product>
     */
    public function comboFlavorOptionsFor(Addon $addon): Collection
    {
        if (count($this->comboBuilder['flavor_ids']) <= 1) {
            return new Collection;
        }

        return $addon->products->whereIn('id', $this->comboBuilder['flavor_ids']);
    }

    /**
     * true quando há um termo de busca com pelo menos 2 caracteres — nesse
     * caso o Blade mostra só `searchResults` e esconde o resto.
     */
    public function isSearching(): bool
    {
        return mb_strlen(trim($this->search)) >= 2;
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function searchResults(): Collection
    {
        if (! $this->isSearching()) {
            return new Collection;
        }

        $term = trim($this->search);

        $products = Product::query()
            ->where(fn (Builder $query) => $this->visibleProducts($query))
            ->whereHas('category', fn (Builder $query) => $query->where('show_in_menu', true))
            ->where('name', 'like', '%'.$term.'%')
            ->with(['category.flavorQuantityOptions', 'category.parent.flavorQuantityOptions'])
            ->orderBy('name')
            ->limit(50)
            ->get();

        return $this->attachPrices($products);
    }

    #[Computed]
    public function categories()
    {
        return Category::query()
            ->whereNull('parent_id')
            ->where('show_in_menu', true)
            ->with([
                'products' => fn ($query) => $this->visibleProducts($query),
                'children' => fn ($query) => $query->where('show_in_menu', true)->orderBy('display_order'),
                'children.products' => fn ($query) => $this->visibleProducts($query),
                'flavorQuantityOptions',
                'children.flavorQuantityOptions',
            ])
            ->orderBy('display_order')
            ->get()
            ->filter(fn (Category $category) => $category->products->isNotEmpty()
                || $category->children->contains(fn (Category $child) => $child->products->isNotEmpty()))
            ->map(function (Category $category) {
                $category->setRelation(
                    'children',
                    $category->children->filter(fn (Category $child) => $child->products->isNotEmpty())->values()
                );

                $category->setRelation('products', $this->attachPrices($category->products));

                foreach ($category->children as $child) {
                    // Deixa o pai já resolvido na subcategoria para
                    // resolvedFlavorQuantityOptions() não disparar query
                    // (o flavorQuantityOptions do pai já veio no eager-load).
                    $child->setRelation('parent', $category);
                    $child->setRelation('products', $this->attachPrices($child->products));
                }

                $category->setAttribute('nav_thumbnail_url', $category->navigationThumbnailUrl());

                return $category;
            })
            ->values();
    }

    /**
     * Localiza uma categoria do cardápio pelo id, incluindo subcategorias —
     * a coleção `categories()` só carrega raízes. Cai para um load direto
     * quando o id vem de um bestseller/busca fora da árvore filtrada.
     */
    public function menuCategory(int $categoryId): ?Category
    {
        foreach ($this->categories as $category) {
            if ($category->id === $categoryId) {
                return $category;
            }

            $child = $category->children->firstWhere('id', $categoryId);

            if ($child !== null) {
                return $child;
            }
        }

        $category = Category::query()
            ->with([
                'flavorQuantityOptions',
                'parent.flavorQuantityOptions',
                'products' => fn ($query) => $this->visibleProducts($query),
            ])
            ->find($categoryId);

        $category?->setRelation('products', $this->attachPrices($category->products));

        return $category;
    }

    #[Computed]
    public function bestsellers()
    {
        $products = Product::query()
            ->where('bestseller_eligible', true)
            ->where(fn ($query) => $this->visibleProducts($query))
            ->with(['category.flavorQuantityOptions', 'category.parent.flavorQuantityOptions'])
            ->orderByDesc('sales_count')
            ->limit(6)
            ->get();

        return $this->attachPrices($products);
    }

    #[Computed]
    public function activePromotions()
    {
        return FlashPromotion::query()
            ->where('is_active', true)
            ->with(['products' => fn ($query) => $query->where('is_visible', true)])
            ->get()
            ->filter(fn (FlashPromotion $promotion) => $promotion->computedStatus() === FlashPromotionStatus::Active)
            ->map(function (FlashPromotion $promotion) {
                $promotion->setRelation('products', $this->attachPrices($promotion->products));

                return $promotion;
            })
            ->values();
    }

    #[Computed]
    public function viewingProduct(): ?Product
    {
        if ($this->viewingProductId === null) {
            return null;
        }

        $product = Product::with(['category.flavorQuantityOptions', 'category.parent.flavorQuantityOptions'])->find($this->viewingProductId);

        return $product ? $this->attachPrice($product) : null;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    private function attachPrices(Collection $products): Collection
    {
        return $products->map(fn (Product $product) => $this->attachPrice($product));
    }

    private function attachPrice(Product $product): Product
    {
        $resolved = app(ResolvePriceForProduct::class)($product);
        $promotion = $resolved->matchedFlashPromotion;

        $product->setAttribute('resolved_price', $resolved->finalPrice);
        $product->setAttribute('resolved_original_price', $resolved->originalPrice);
        $product->setAttribute('resolved_flavor_combo_blocked', $promotion !== null && ! $promotion->allows_flavors);
        $product->setAttribute('resolved_flavor_combo_max', $promotion?->allows_flavors ? $promotion->max_flavors : null);
        $product->setAttribute('resolved_has_addons', $product->addons()->exists());

        return $product;
    }

    #[Computed]
    public function businessHours()
    {
        return app(CheckBusinessHours::class)();
    }

    /**
     * Preview de nome(s) + preço do combo em montagem, só quando a seleção
     * já bate com a quantidade exigida — mesmo ponto em que "Adicionar"
     * fica habilitado no modal.
     *
     * @return array{names: string, unit_price: float}|null
     */
    #[Computed]
    public function comboPreview(): ?array
    {
        $requiredCount = $this->comboBuilder['required_count'];
        $flavorIds = $this->comboBuilder['flavor_ids'];

        if ($requiredCount === null || count($flavorIds) !== $requiredCount) {
            return null;
        }

        $item = $requiredCount === 1
            ? ['type' => 'simple', 'product_id' => $flavorIds[0], 'flavor_ids' => [], 'quantity' => 1, 'note' => null]
            : ['type' => 'combo', 'product_id' => $flavorIds[0], 'flavor_ids' => $flavorIds, 'quantity' => 1, 'note' => null];

        try {
            $resolved = app(ResolvePriceForCartLine::class)($item);
        } catch (InvalidArgumentException) {
            return null;
        }

        $products = Product::whereIn('id', $flavorIds)->get()->keyBy('id');
        $names = collect($flavorIds)->map(fn (int $id) => $products->get($id)?->name)->filter()->implode(' / ');

        return ['names' => $names, 'unit_price' => $resolved['unit_price']];
    }

    #[Computed]
    public function cartLines(): array
    {
        $resolve = app(ResolvePriceForCartLine::class);
        $lines = [];

        $cartItems = Cart::items();
        $addonIds = collect($cartItems)->flatMap(fn (array $item) => $item['addons'] ?? [])->pluck('addon_id')->unique()->values();
        $addonNames = $addonIds->isEmpty() ? collect() : Addon::whereIn('id', $addonIds)->pluck('name', 'id');
        $flavorIds = collect($cartItems)->flatMap(fn (array $item) => $item['flavor_ids'])->unique()->values();
        $flavorNames = $flavorIds->isEmpty() ? collect() : Product::whereIn('id', $flavorIds)->pluck('name', 'id');

        foreach ($cartItems as $index => $item) {
            try {
                $resolved = $resolve($item);
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($item['type'] === 'combo') {
                $flavorProducts = Product::whereIn('id', $item['flavor_ids'])->get();
                $name = $flavorProducts->pluck('name')->implode(' / ');
                $imageUrl = $flavorProducts->first()?->image_url;
            } else {
                $product = Product::find($item['product_id']);
                $name = $product?->name ?? 'Produto removido';
                $imageUrl = $product?->image_url;
            }

            $addonsDisplay = collect($item['addons'] ?? [])->map(function (array $selection) use ($addonNames, $flavorNames) {
                $addonName = $addonNames->get($selection['addon_id'], 'Adicional removido');
                $target = $selection['target'] !== null ? ($flavorNames->get($selection['target']) ?? 'sabor removido') : 'produto inteiro';

                return "{$selection['quantity']}x {$addonName} ({$target})";
            })->all();

            $lines[] = [
                'index' => $index,
                'name' => $name,
                'quantity' => $item['quantity'],
                'unit_price' => $resolved['unit_price'],
                'original_unit_price' => $resolved['original_unit_price'],
                'addons_total' => $resolved['addons_total'],
                'addons_display' => $addonsDisplay,
                'line_total' => round(($resolved['unit_price'] + $resolved['addons_total']) * $item['quantity'], 2),
                'note' => $item['note'],
                'image_url' => $imageUrl,
            ];
        }

        return $lines;
    }

    #[Computed]
    public function cartTotal(): float
    {
        return round(array_sum(array_column($this->cartLines, 'line_total')), 2);
    }

    #[Computed]
    public function cartItemCount(): int
    {
        return array_sum(array_column($this->cartLines, 'quantity'));
    }

    #[Computed]
    public function cartDiscount(): float
    {
        $discount = array_sum(array_map(
            fn (array $line) => ($line['original_unit_price'] - $line['unit_price']) * $line['quantity'],
            $this->cartLines
        ));

        return round($discount, 2);
    }

    private function visibleProducts(Builder|Relation $query): Builder|Relation
    {
        return $query
            ->where('is_visible', true)
            ->where(function (Builder $q) {
                $q->where('controls_stock', false)
                    ->orWhere('stock_quantity', '>', 0)
                    ->orWhere('show_when_out_of_stock', true);
            })
            ->orderBy('display_order');
    }

    public function render()
    {
        return view('livewire.menu')
            ->layout('components.layouts.public', ['tenant' => CurrentTenant::get()]);
    }
}
