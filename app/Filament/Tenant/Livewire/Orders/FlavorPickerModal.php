<?php

namespace App\Filament\Tenant\Livewire\Orders;

use App\Actions\Menu\ResolvePriceForCartLine;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductGift;
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
        $this->errorMessage = null;
        $this->open = true;
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

        $item = $requiredCount === 1
            ? ['type' => 'simple', 'product_id' => $flavorIds[0], 'flavor_ids' => [], 'quantity' => 1, 'note' => null, 'addons' => [], 'gifts' => []]
            : ['type' => 'combo', 'product_id' => $flavorIds[0], 'flavor_ids' => $flavorIds, 'quantity' => 1, 'note' => null, 'addons' => [], 'gifts' => []];

        try {
            app(ResolvePriceForCartLine::class)($item);
        } catch (InvalidArgumentException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $anchorIds = $requiredCount === 1 ? [$flavorIds[0]] : $flavorIds;

        $hasExtras = ProductAddon::whereIn('product_id', $anchorIds)->exists()
            || ProductGift::whereIn('product_id', $anchorIds)->where('is_active', true)->exists();

        if ($hasExtras) {
            $this->dispatch('order-addons-requested', type: $item['type'], productId: $item['product_id'], flavorIds: $item['flavor_ids']);
            $this->closeModal();

            return;
        }

        $this->dispatch('order-cart-line-confirmed', item: $item);
        $this->closeModal();
    }

    public function closeModal(): void
    {
        $this->open = false;
        $this->comboBuilder = ['category_id' => null, 'quantity_option_id' => null, 'required_count' => null, 'flavor_ids' => []];
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

    #[Computed]
    public function availableFlavors(): Collection
    {
        if ($this->comboBuilder['category_id'] === null) {
            return collect();
        }

        return Product::where('category_id', $this->comboBuilder['category_id'])
            ->where('is_visible', true)
            ->orderBy('display_order')
            ->get();
    }

    public function render()
    {
        return view('filament.tenant.livewire.orders.flavor-picker-modal');
    }
}
