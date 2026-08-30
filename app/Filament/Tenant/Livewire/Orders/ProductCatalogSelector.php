<?php

namespace App\Filament\Tenant\Livewire\Orders;

use App\Actions\Menu\ResolvePriceForProduct;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Catálogo de produtos pra montagem de pedido no painel (AttendOrder).
 * Puramente de apresentação — não conhece carrinho/pedido, só dispara
 * eventos de domínio pro pai decidir o que fazer (mesmo padrão do
 * VendaProdutoSelector do Pizzaria-App).
 */
class ProductCatalogSelector extends Component
{
    public ?int $categoryId = null;

    public string $search = '';

    public function selectCategory(?int $categoryId): void
    {
        $this->categoryId = $categoryId;
    }

    public function selectProduct(int $productId): void
    {
        $product = Product::with('category')->find($productId);

        if (! $product) {
            return;
        }

        if ($product->category?->allows_flavors) {
            $this->dispatch('order-combo-requested', categoryId: $product->category_id, initialProductId: $productId);

            return;
        }

        if ($product->addons()->exists()) {
            $this->dispatch('order-addons-requested', type: 'simple', productId: $productId, flavorIds: []);

            return;
        }

        $this->dispatch('order-item-selected', productId: $productId);
    }

    #[Computed]
    public function categories(): Collection
    {
        return Category::query()
            ->whereNull('parent_id')
            ->where('show_in_menu', true)
            ->orderBy('display_order')
            ->get();
    }

    #[Computed]
    public function products(): Collection
    {
        return Product::query()
            ->when($this->categoryId, fn (Builder $query) => $query->where('category_id', $this->categoryId))
            ->when($this->search !== '', fn (Builder $query) => $query->where('name', 'like', "%{$this->search}%"))
            ->where('is_visible', true)
            ->where(function (Builder $query) {
                $query->where('controls_stock', false)
                    ->orWhere('stock_quantity', '>', 0)
                    ->orWhere('show_when_out_of_stock', true);
            })
            ->orderBy('display_order')
            ->get()
            ->map(function (Product $product) {
                $resolved = app(ResolvePriceForProduct::class)($product);

                $product->setAttribute('resolved_price', $resolved->finalPrice);
                $product->setAttribute('resolved_original_price', $resolved->originalPrice);
                $product->setAttribute('low_stock', $product->controls_stock && $product->stock_quantity <= 5);

                return $product;
            });
    }

    public function render()
    {
        return view('filament.tenant.livewire.orders.product-catalog-selector');
    }
}
