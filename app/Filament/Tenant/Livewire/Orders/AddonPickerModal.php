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
        $this->wantsAddons = null;
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

        $item = count($this->flavorIds) > 1
            ? ['type' => 'combo', 'product_id' => $this->flavorIds[0], 'flavor_ids' => $this->flavorIds, 'quantity' => 1, 'note' => null, 'addons' => $addons]
            : ['type' => 'simple', 'product_id' => $this->productId, 'flavor_ids' => [], 'quantity' => 1, 'note' => null, 'addons' => $addons];

        try {
            app(ResolvePriceForCartLine::class)($item);
        } catch (InvalidArgumentException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->dispatch('order-cart-line-confirmed', item: $item);
        $this->closeModal();
    }

    public function closeModal(): void
    {
        $this->open = false;
        $this->type = 'simple';
        $this->productId = null;
        $this->flavorIds = [];
        $this->selections = [];
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
