<?php

namespace App\Filament\Tenant\Livewire\Orders;

use App\Actions\Menu\ResolvePriceForCartLine;
use App\Actions\Menu\ResolvePriceForProduct;
use App\Models\Addon;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Modal de seleção de sabores (N-of-M — pizza meio a meio, três sabores
 * etc.), mesma lógica de estado do comboBuilder de app/Livewire/Menu.php,
 * adaptada pro contexto do painel: em vez de gravar no Cart de sessão,
 * valida no servidor via ResolvePriceForCartLine e devolve a linha pronta
 * pro pai (AttendOrder) via evento — nunca confia só na seleção do front.
 */
class FlavorPickerModal extends Component
{
    public bool $open = false;

    /** @var array{category_id: ?int, quantity_option_id: ?int, required_count: ?int, flavor_ids: array<int>} */
    public array $comboBuilder = [
        'category_id' => null,
        'quantity_option_id' => null,
        'required_count' => null,
        'flavor_ids' => [],
    ];

    /** Observação do item (digitada no rodapé do modal, como na tela original). */
    public ?string $note = null;

    public ?string $errorMessage = null;

    #[On('order-combo-requested')]
    public function startCombo(int $categoryId, ?int $initialProductId = null): void
    {
        $category = $this->loadCategory($categoryId);
        $defaultOption = $category?->resolvedFlavorQuantityOptions()->first();

        $this->comboBuilder = [
            'category_id' => $categoryId,
            'quantity_option_id' => $defaultOption?->id,
            'required_count' => $defaultOption?->flavor_count,
            'flavor_ids' => $initialProductId ? [$initialProductId] : [],
        ];
        $this->note = null;
        $this->errorMessage = null;
        $this->open = true;
    }

    /**
     * "Voltar" do passo de adicionais: reabre o modal com a seleção de sabores preservada
     * (confirmCombo só o esconde quando há adicionais/brindes a perguntar).
     */
    #[On('order-combo-resume')]
    public function resume(): void
    {
        if ($this->comboBuilder['category_id'] !== null) {
            $this->open = true;
        }
    }

    /** O passo de adicionais terminou (linha confirmada ou cancelada): descarta o que ficou guardado. */
    #[On('order-addons-cancelled')]
    #[On('order-cart-line-confirmed')]
    public function discard(): void
    {
        $this->closeModal();
    }

    public function selectFlavorQuantity(int $optionId): void
    {
        $category = $this->loadCategory($this->comboBuilder['category_id']);
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

    public function confirmCombo(): void
    {
        $requiredCount = $this->comboBuilder['required_count'];
        $flavorIds = $this->comboBuilder['flavor_ids'];

        if ($requiredCount === null || count($flavorIds) !== $requiredCount) {
            return;
        }

        $note = blank($this->note) ? null : mb_substr(trim($this->note), 0, 255);

        $item = $requiredCount === 1
            ? ['type' => 'simple', 'product_id' => $flavorIds[0], 'flavor_ids' => [], 'quantity' => 1, 'note' => $note, 'addons' => [], 'gifts' => []]
            : ['type' => 'combo', 'product_id' => $flavorIds[0], 'flavor_ids' => $flavorIds, 'quantity' => 1, 'note' => $note, 'addons' => [], 'gifts' => []];

        try {
            app(ResolvePriceForCartLine::class)($item);
        } catch (InvalidArgumentException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        if ($this->hasExtras) {
            // O modal só some da tela: "Voltar" no passo de adicionais o reabre com a seleção intacta.
            $this->dispatch('order-addons-requested', type: $item['type'], productId: $item['product_id'], flavorIds: $item['flavor_ids'], note: $note, canGoBack: true);
            $this->open = false;

            return;
        }

        $this->dispatch('order-cart-line-confirmed', item: $item);
        $this->closeModal();
    }

    public function closeModal(): void
    {
        $this->open = false;
        $this->comboBuilder = ['category_id' => null, 'quantity_option_id' => null, 'required_count' => null, 'flavor_ids' => []];
        $this->note = null;
        $this->errorMessage = null;
    }

    private function loadCategory(?int $categoryId): ?Category
    {
        if ($categoryId === null) {
            return null;
        }

        return Category::with(['flavorQuantityOptions', 'parent.flavorQuantityOptions'])->find($categoryId);
    }

    #[Computed]
    public function currentCategory(): ?Category
    {
        return $this->loadCategory($this->comboBuilder['category_id']);
    }

    /**
     * Sabores da categoria com o preço já resolvido (promoção/relâmpago), para a lista do modal.
     * A regra de preço continua sendo a do servidor em ResolvePriceForCartLine ao confirmar.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function availableFlavors(): Collection
    {
        if ($this->comboBuilder['category_id'] === null) {
            return collect();
        }

        return Product::where('category_id', $this->comboBuilder['category_id'])
            ->where('show_in_waiter_menu', true)
            ->orderBy('display_order')
            ->get()
            ->map(function (Product $product): Product {
                $resolved = app(ResolvePriceForProduct::class)($product);

                $product->setAttribute('resolved_price', $resolved->finalPrice);
                $product->setAttribute('resolved_original_price', $resolved->originalPrice);

                return $product;
            });
    }

    /**
     * Sabores já escolhidos, na ordem da escolha (1º, 2º…).
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function chosenFlavors(): Collection
    {
        $flavors = $this->availableFlavors->keyBy('id');

        return collect($this->comboBuilder['flavor_ids'])
            ->map(fn (int $id): ?Product => $flavors->get($id))
            ->filter()
            ->values();
    }

    /**
     * Prévia do preço da pizza: média dos preços dos sabores escolhidos (RN-16), a mesma regra de
     * ResolvePriceForCartLine::resolveCombo, que segue sendo a fonte de verdade ao confirmar.
     */
    #[Computed]
    public function previewPrice(): ?float
    {
        if ($this->chosenFlavors->isEmpty()) {
            return null;
        }

        return round($this->chosenFlavors->avg('resolved_price'), 2);
    }

    /**
     * Há algo a perguntar nos sabores escolhidos (adicionais ou brindes), então "Continuar" abre mais um passo.
     * Usa o mesmo critério da lista do AddonPickerModal, para não levar a um passo vazio.
     */
    #[Computed]
    public function hasExtras(): bool
    {
        $anchorIds = $this->comboBuilder['flavor_ids'];

        if ($anchorIds === []) {
            return false;
        }

        if (Addon::whereHas('products', fn ($query) => $query->whereIn('products.id', $anchorIds))->exists()) {
            return true;
        }

        $flavorCount = count($anchorIds);

        return Product::whereIn('id', $anchorIds)
            ->with(['gifts' => fn ($query) => $query->wherePivot('is_active', true)])
            ->get()
            ->flatMap(fn (Product $anchor) => $anchor->gifts)
            ->contains(function (Product $gift) use ($anchorIds, $flavorCount): bool {
                if (in_array($gift->id, $anchorIds, true)) {
                    return false;
                }

                $counts = $gift->pivot->flavor_counts;

                return empty($counts) || in_array($flavorCount, array_map('intval', $counts), true);
            });
    }

    public function render()
    {
        return view('filament.tenant.livewire.orders.flavor-picker-modal');
    }
}
