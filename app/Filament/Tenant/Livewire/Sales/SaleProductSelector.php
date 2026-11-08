<?php

namespace App\Filament\Tenant\Livewire\Sales;

use App\Actions\Menu\ResolvePriceForProduct;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Aba "Produtos" da Nova Venda: catálogo com busca e filtro por categoria. Apresentação pura —
 * não persiste nada e não conhece a venda. Um clique só dispara `product-selected`, capturado pela
 * página (NewSale::addStandaloneProduct), que cria o rascunho se preciso e lança o item. O preço
 * mostrado é o mesmo que será cobrado: o do cardápio sem promoção relâmpago (item avulso).
 */
class SaleProductSelector extends Component
{
    public string $search = '';

    public ?int $categoryId = null;

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->whereHas('products', fn ($products) => $products->where('show_in_waiter_menu', true))->orderBy('display_order')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function products(): Collection
    {
        return Product::query()->with('category')
            ->where('show_in_waiter_menu', true)
            ->when($this->search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($this->search, '%_\\').'%'))
            ->when($this->categoryId, fn ($query) => $query->where('category_id', $this->categoryId))
            ->orderBy('display_order')
            ->orderBy('name')
            ->limit(60)
            ->get();
    }

    public function selectProduct(int $productId): void
    {
        $this->dispatch('product-selected', productId: $productId);
    }

    /**
     * @return array{price: float, original: float, onPromo: bool}
     */
    public function priceOf(Product $product): array
    {
        $resolved = app(ResolvePriceForProduct::class)->withoutFlashPromotion($product);

        return [
            'price' => $resolved->finalPrice,
            'original' => $resolved->originalPrice,
            'onPromo' => $resolved->finalPrice > 0 && $resolved->finalPrice < $resolved->originalPrice,
        ];
    }

    public function render()
    {
        return view('filament.tenant.livewire.sales.sale-product-selector');
    }
}
