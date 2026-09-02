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

    public ?int $subcategoryId = null;

    public string $search = '';

    public function selectCategory(?int $categoryId): void
    {
        $this->categoryId = $categoryId;
        $this->subcategoryId = null;
    }

    public function selectSubcategory(?int $subcategoryId): void
    {
        $this->subcategoryId = $subcategoryId;
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

        // Produto simples com adicionais e/ou brindes ativos: abre o modal
        // (AddonPickerModal cobre os dois) pra o atendente escolher antes de
        // a linha entrar no carrinho.
        if ($product->addons()->exists() || $product->gifts()->wherePivot('is_active', true)->exists()) {
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
            ->with([
                'children' => fn ($query) => $query->where('show_in_menu', true)->orderBy('display_order'),
                'products:id,category_id,image_path',
                'children.products:id,category_id,image_path',
            ])
            ->orderBy('display_order')
            ->get()
            ->each(fn (Category $category) => $category->setAttribute(
                'nav_thumbnail_url',
                $category->navigationThumbnailUrl(),
            ));
    }

    #[Computed]
    public function products(): Collection
    {
        return Product::query()
            ->when($this->categoryId, function (Builder $query) {
                $root = $this->categories->firstWhere('id', $this->categoryId);

                $categoryIds = match (true) {
                    $this->subcategoryId !== null => [$this->subcategoryId],
                    $root !== null => $root->children->pluck('id')->push($root->id)->all(),
                    default => [$this->categoryId],
                };

                $query->whereIn('category_id', $categoryIds);
            })
            ->when($this->search !== '', fn (Builder $query) => $query->where('name', 'like', "%{$this->search}%"))
            ->with('category:id,name')
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
