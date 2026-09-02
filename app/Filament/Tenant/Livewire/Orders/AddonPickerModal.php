<?php

namespace App\Filament\Tenant\Livewire\Orders;

use App\Actions\Menu\ResolvePriceForCartLine;
use App\Models\Addon;
use App\Models\Product;
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

    /**
     * null = ainda não perguntado; true = usuário quer escolher adicionais
     * (mostra a lista); false = usuário optou por não adicionar nada
     * (confirma a linha sem adicionais direto, sem precisar clicar de novo).
     */
    public ?bool $wantsAddons = null;

    public ?string $errorMessage = null;

    /**
     * @param  array<int>  $flavorIds
     */
    #[On('order-addons-requested')]
    public function open(string $type, int $productId, array $flavorIds = []): void
    {
        $this->type = $type;
        $this->productId = $productId;
        $this->flavorIds = $flavorIds;
        $this->selections = [];
        $this->giftSelections = [];
        $this->editingIndex = null;
        // Pula o gate "quer adicionais?" quando não há adicionais, ou quando há
        // brinde a escolher (a lista já mostra tudo; adicional fica em qtd 0).
        $this->wantsAddons = ($this->availableAddons->isEmpty() || $this->availableGifts->isNotEmpty()) ? true : null;
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
        $this->wantsAddons = true;
        $this->errorMessage = null;
        $this->open = true;
    }

    public function chooseWantsAddons(bool $wantsAddons): void
    {
        $this->wantsAddons = $wantsAddons;

        if (! $wantsAddons) {
            $this->confirmAddons();
        }
    }

    public function setQuantity(int $addonId, int $quantity): void
    {
        if ($quantity <= 0) {
            unset($this->selections[$addonId]);

            return;
        }

        $this->selections[$addonId] = [
            'quantity' => $quantity,
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
        $addons = collect($this->selections)
            ->map(fn (array $selection, int $addonId) => ['addon_id' => $addonId, 'quantity' => $selection['quantity'], 'target' => $selection['target']])
            ->values()
            ->all();

        $gifts = collect($this->giftSelections)
            ->filter()
            ->map(fn (bool $accepted, int $giftProductId) => ['gift_product_id' => $giftProductId, 'accepted' => true])
            ->values()
            ->all();

        $item = count($this->flavorIds) > 1
            ? ['type' => 'combo', 'product_id' => $this->flavorIds[0], 'flavor_ids' => $this->flavorIds, 'quantity' => 1, 'note' => null, 'addons' => $addons, 'gifts' => $gifts]
            : ['type' => 'simple', 'product_id' => $this->productId, 'flavor_ids' => [], 'quantity' => 1, 'note' => null, 'addons' => $addons, 'gifts' => $gifts];

        try {
            app(ResolvePriceForCartLine::class)($item);
        } catch (InvalidArgumentException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        if ($this->editingIndex !== null) {
            $this->dispatch('order-line-addons-updated', index: $this->editingIndex, addons: $addons);
        } else {
            $this->dispatch('order-cart-line-confirmed', item: $item);
        }

        $this->closeModal();
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
        $this->wantsAddons = null;
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
