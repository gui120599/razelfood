<?php

namespace App\Filament\Tenant\Livewire\Orders;

use App\Actions\Menu\ResolvePriceForCartLine;
use App\Actions\Menu\ResolvePriceForProduct;
use App\Models\Addon;
use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Modal de seleção de adicionais (RN-48) — irmão de FlavorPickerModal, mesma
 * composição orientada a eventos: escuta o pedido de um item já resolvido
 * (produto simples, ou combo já com os sabores escolhidos), valida no
 * servidor via ResolvePriceForCartLine e devolve a linha pronta pro pai
 * (AttendOrder) pelo MESMO evento `order-cart-line-confirmed` já usado por
 * FlavorPickerModal — AttendOrder não precisa saber de onde a linha veio.
 */
class AddonPickerModal extends Component
{
    public bool $open = false;

    public string $type = 'simple';

    public ?int $productId = null;

    /** @var array<int> */
    public array $flavorIds = [];

    /** @var array<int, array{quantity: int, target: ?int}> keyed por addon_id */
    public array $selections = [];

    /**
     * Brindes aceitos pelo atendente para a linha em montagem (RN-53) — keyed
     * por gift_product_id => true. Quantidade/validade resolvidas no servidor.
     *
     * @var array<int, bool>
     */
    public array $giftSelections = [];

    /**
     * null = fluxo normal (adicionar item novo ao carrinho, confirma via
     * `order-cart-line-confirmed`); int = editando os adicionais de uma linha
     * já existente naquele índice, confirma via `order-line-addons-updated`.
     */
    public ?int $editingIndex = null;

    /** Quantidade do item (produto simples); pizza de vários sabores entra com 1 e ajusta no carrinho. */
    public int $quantity = 1;

    /** Observação do item: digitada aqui (produto simples) ou trazida do modal de sabores. */
    public ?string $note = null;

    /** Veio do modal de sabores: o botão "Voltar" o reabre com a seleção preservada. */
    public bool $canGoBack = false;

    public ?string $errorMessage = null;

    /**
     * @param  array<int>  $flavorIds
     */
    #[On('order-addons-requested')]
    public function open(string $type, int $productId, array $flavorIds = [], ?string $note = null, bool $canGoBack = false): void
    {
        $this->type = $type;
        $this->productId = $productId;
        $this->flavorIds = $flavorIds;
        $this->selections = [];
        $this->giftSelections = [];
        $this->editingIndex = null;
        $this->quantity = 1;
        $this->note = $note;
        $this->canGoBack = $canGoBack;
        $this->errorMessage = null;
        $this->open = true;
    }

    /**
     * Reabre o modal para gerenciar os adicionais de uma linha já no carrinho
     * (AttendOrder). Pula o gate sim/não — o atendente já pediu explicitamente
     * pra mexer nos adicionais — e pré-carrega as escolhas atuais.
     *
     * @param  array<int>  $flavorIds
     * @param  array<int, array{addon_id:int, quantity:int, target:?int}>  $addons
     */
    #[On('order-line-addons-edit-requested')]
    public function editForLine(int $index, string $type, ?int $productId, array $flavorIds, array $addons): void
    {
        $this->type = $type;
        $this->productId = $productId;
        $this->flavorIds = $flavorIds;
        $this->selections = collect($addons)
            ->mapWithKeys(fn (array $addon) => [$addon['addon_id'] => ['quantity' => $addon['quantity'], 'target' => $addon['target']]])
            ->all();
        // Edição de adicionais de uma linha existente não mexe nos brindes dela.
        $this->giftSelections = [];
        $this->editingIndex = $index;
        $this->quantity = 1;
        $this->note = null;
        $this->canGoBack = false;
        $this->errorMessage = null;
        $this->open = true;
    }

    public function incrementQuantity(): void
    {
        $this->quantity = min(99, $this->quantity + 1);
    }

    public function decrementQuantity(): void
    {
        $this->quantity = max(1, $this->quantity - 1);
    }

    /** "Voltar" do passo de adicionais: reabre o modal de sabores (que guardou a seleção) e fecha este. */
    public function goBack(): void
    {
        $this->dispatch('order-combo-resume');
        $this->closeModal();
    }

    /** Cancelar/fechar: quem veio do modal de sabores avisa que a montagem foi descartada. */
    public function cancel(): void
    {
        if ($this->canGoBack) {
            $this->dispatch('order-addons-cancelled');
        }

        $this->closeModal();
    }

    public function setQuantity(int $addonId, int $quantity): void
    {
        if ($quantity <= 0) {
            unset($this->selections[$addonId]);

            return;
        }

        $addon = $this->availableAddons->firstWhere('id', $addonId);
        $max = $addon ? $this->maxQuantityOf($addon) : null;

        $this->selections[$addonId] = [
            'quantity' => $max !== null ? min($quantity, $max) : $quantity,
            'target' => $this->selections[$addonId]['target'] ?? null,
        ];
    }

    public function setTarget(int $addonId, ?int $target): void
    {
        if (! isset($this->selections[$addonId])) {
            return;
        }

        $this->selections[$addonId]['target'] = $target;
    }

    public function toggleGift(int $giftProductId): void
    {
        if ($this->giftSelections[$giftProductId] ?? false) {
            unset($this->giftSelections[$giftProductId]);

            return;
        }

        $this->giftSelections[$giftProductId] = true;
    }

    /**
     * Atalho pra quando o cliente já está vendo a lista mas decide não
     * levar nenhum adicional (achou caro, não gostou das opções etc.) —
     * limpa qualquer seleção parcial e confirma a linha vazia, sem
     * precisar zerar quantidade item por item.
     */
    public function skipAddons(): void
    {
        $this->selections = [];
        $this->confirmAddons();
    }

    /**
     * Confirma a linha — funciona com ou sem nenhum adicional selecionado
     * (atendente pode simplesmente não querer nenhum adicional pro item).
     */
    public function confirmAddons(): void
    {
        // Segundo clique do atendente depois de a linha já ter sido confirmada (modal fechado, estado zerado).
        if (! $this->open || ($this->productId === null && count($this->flavorIds) < 2)) {
            return;
        }

        $item = $this->buildItem();

        try {
            app(ResolvePriceForCartLine::class)($item);
        } catch (InvalidArgumentException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        } catch (ModelNotFoundException) {
            $this->errorMessage = 'Produto não encontrado. Feche e escolha o item de novo.';

            return;
        }

        if ($this->editingIndex !== null) {
            $this->dispatch('order-line-addons-updated', index: $this->editingIndex, addons: $item['addons']);
        } else {
            $this->dispatch('order-cart-line-confirmed', item: $item);
        }

        $this->closeModal();
    }

    /**
     * A linha como o carrinho a guarda (só ids e quantidades; nome/preço são resolvidos no servidor).
     *
     * @return array{type: string, product_id: ?int, flavor_ids: array<int>, quantity: int, note: ?string, addons: array<int, array{addon_id: int, quantity: int, target: ?int}>, gifts: array<int, array{gift_product_id: int, accepted: bool}>}
     */
    private function buildItem(): array
    {
        $addons = collect($this->selections)
            ->map(fn (array $selection, int $addonId) => ['addon_id' => $addonId, 'quantity' => $selection['quantity'], 'target' => $selection['target']])
            ->values()
            ->all();

        $gifts = collect($this->giftSelections)
            ->filter()
            ->map(fn (bool $accepted, int $giftProductId) => ['gift_product_id' => $giftProductId, 'accepted' => true])
            ->values()
            ->all();

        $note = blank($this->note) ? null : mb_substr(trim($this->note), 0, 255);
        $quantity = max(1, min(99, $this->quantity));

        return count($this->flavorIds) > 1
            ? ['type' => 'combo', 'product_id' => $this->flavorIds[0], 'flavor_ids' => $this->flavorIds, 'quantity' => $quantity, 'note' => $note, 'addons' => $addons, 'gifts' => $gifts]
            : ['type' => 'simple', 'product_id' => $this->productId, 'flavor_ids' => [], 'quantity' => $quantity, 'note' => $note, 'addons' => $addons, 'gifts' => $gifts];
    }

    public function isCombo(): bool
    {
        return count($this->flavorIds) > 1;
    }

    /**
     * Preço do item como o servidor o resolve agora (unitário, adicionais e total da linha), só para
     * exibição; null quando a seleção atual não é válida (o erro aparece ao confirmar).
     *
     * @return array{unit: float, addons: float, total: float}|null
     */
    #[Computed]
    public function preview(): ?array
    {
        // Na edição de uma linha do carrinho não há total a mostrar (e o produto pode ter saído do catálogo).
        if ($this->productId === null || $this->editingIndex !== null) {
            return null;
        }

        $item = $this->buildItem();

        try {
            $resolved = app(ResolvePriceForCartLine::class)($item);
        } catch (InvalidArgumentException|ModelNotFoundException) {
            return null;
        }

        return [
            'unit' => $resolved['unit_price'],
            'addons' => $resolved['addons_total'],
            'total' => round(($resolved['unit_price'] + $resolved['addons_total']) * $item['quantity'], 2),
        ];
    }

    /**
     * Produto do cabeçalho do modal de item simples (foto, preço e estoque).
     */
    #[Computed]
    public function headerProduct(): ?Product
    {
        if ($this->productId === null) {
            return null;
        }

        $product = Product::with('category:id,name')->find($this->productId);

        if ($product === null) {
            return null;
        }

        $resolved = app(ResolvePriceForProduct::class)($product);
        $product->setAttribute('resolved_price', $resolved->finalPrice);
        $product->setAttribute('resolved_original_price', $resolved->originalPrice);

        return $product;
    }

    /**
     * Nomes dos sabores escolhidos (subtítulo do passo "Quer adicionar algo?").
     */
    #[Computed]
    public function flavorNames(): string
    {
        return Product::whereIn('id', $this->flavorIds)->get()->sortBy(fn (Product $p) => array_search($p->id, $this->flavorIds, true))->pluck('name')->implode(' / ');
    }

    public function closeModal(): void
    {
        $this->open = false;
        $this->type = 'simple';
        $this->productId = null;
        $this->flavorIds = [];
        $this->selections = [];
        $this->giftSelections = [];
        $this->editingIndex = null;
        $this->quantity = 1;
        $this->note = null;
        $this->canGoBack = false;
        $this->errorMessage = null;
    }

    /**
     * Adicionais disponíveis pro contexto atual, cada um com a lista de
     * `flavor_ids` a que está de fato anexado (pra montar o seletor de alvo
     * — "produto inteiro" só aparece quando o adicional está anexado a
     * TODOS os sabores do combo, mesma regra validada de novo no servidor
     * em ResolvePriceForCartLine::resolveAddons()).
     *
     * @return Collection<int, Addon>
     */
    #[Computed]
    public function availableAddons(): Collection
    {
        $productIds = count($this->flavorIds) > 1 ? $this->flavorIds : [$this->productId];

        return Addon::whereHas('products', fn ($query) => $query->whereIn('products.id', $productIds))
            ->with(['products' => fn ($query) => $query->whereIn('products.id', $productIds)])
            ->orderBy('display_order')
            ->get();
    }

    /**
     * Brindes ativos disponíveis pro contexto atual (RN-53), filtrados pela
     * quantidade de sabores e deduplicados por produto. Sempre resolvidos de
     * novo no servidor em ResolvePriceForCartLine::resolveGifts().
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function availableGifts(): Collection
    {
        $anchorIds = count($this->flavorIds) > 1 ? $this->flavorIds : array_filter([$this->productId]);

        if (empty($anchorIds)) {
            return collect();
        }

        $flavorCount = count($this->flavorIds) > 1 ? count($this->flavorIds) : 1;

        return Product::whereIn('id', $anchorIds)
            ->with(['gifts' => fn ($query) => $query->wherePivot('is_active', true)->orderBy('display_order')])
            ->get()
            ->flatMap(fn (Product $anchor) => $anchor->gifts)
            ->filter(function (Product $gift) use ($flavorCount, $anchorIds) {
                if (in_array($gift->id, $anchorIds, true)) {
                    return false;
                }

                $counts = $gift->pivot->flavor_counts;

                return empty($counts) || in_array($flavorCount, array_map('intval', $counts), true);
            })
            ->unique('id')
            ->values();
    }

    /**
     * Pivô do adicional com o produto-âncora do item (o produto simples ou o 1º sabor): é de lá que o
     * servidor tira o preço específico e o teto de quantidade (ResolvePriceForCartLine::resolveAddons()).
     */
    private function anchorPivot(Addon $addon): mixed
    {
        $anchorId = count($this->flavorIds) > 1 ? $this->flavorIds[0] : $this->productId;

        return $addon->products->firstWhere('id', $anchorId)?->pivot;
    }

    /** Preço unitário do adicional para este item (preço do vínculo com o produto, senão o do adicional). */
    public function priceOf(Addon $addon): float
    {
        return (float) ($this->anchorPivot($addon)?->price ?? $addon->price);
    }

    public function maxQuantityOf(Addon $addon): ?int
    {
        $max = $this->anchorPivot($addon)?->max_quantity;

        return $max === null ? null : (int) $max;
    }

    public function allowsWholeProduct(Addon $addon): bool
    {
        if (count($this->flavorIds) <= 1) {
            return true;
        }

        return $addon->products->pluck('id')->intersect($this->flavorIds)->count() === count($this->flavorIds);
    }

    /**
     * @return Collection<int, Product>
     */
    public function flavorOptionsFor(Addon $addon): Collection
    {
        if (count($this->flavorIds) <= 1) {
            return collect();
        }

        return $addon->products->whereIn('id', $this->flavorIds);
    }

    public function render()
    {
        return view('filament.tenant.livewire.orders.addon-picker-modal');
    }
}
