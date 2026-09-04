<?php

namespace App\Filament\Tenant\Pages;

use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\OrderUrgencyLevel;
use App\Filament\Tenant\Support\OrderStatusActions;
use App\Models\Addon;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductionLine;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use App\Support\Orders\GiftLineLabel;
use App\Support\Orders\OrderUrgencyResolver;
use App\Support\Orders\ResolveActiveShiftStart;
use App\Support\Preferences\PersistsFilterPreferences;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

class Kitchen extends Page
{
    // Alias em vez de sobrescrever direto: precisamos combinar a checagem de
    // feature (RN-43) com a permissão do Shield, e a `canAccess()` que a
    // classe declara abaixo já shadowa o método do trait — sem o alias,
    // perderíamos a checagem de permissão do HasPageShield por completo.
    use HasPageShield {
        canAccess as pageShieldCanAccess;
        shouldRegisterNavigation as pageShieldShouldRegisterNavigation;
    }
    use PersistsFilterPreferences;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFire;

    protected static string|UnitEnum|null $navigationGroup = 'Pedidos';

    protected static ?string $navigationLabel = 'Central de Pedidos';

    protected static ?string $title = 'Central de Pedidos';

    // Slug mantido de propósito: evita quebrar atalhos/favoritos já salvos pelos operadores.
    protected static ?string $slug = 'cozinha';

    protected string $view = 'filament.tenant.pages.kitchen';

    public static function canAccess(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::CENTRAL_DE_PEDIDOS) ?? false) && static::pageShieldCanAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::CENTRAL_DE_PEDIDOS) ?? false) && static::pageShieldShouldRegisterNavigation();
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getHeading(): string
    {
        return 'Central de Pedidos';
    }

    public function getSubheading(): string
    {
        return 'Acompanhe os pedidos da operação em tempo real.';
    }

    /** @var array<int, OrderStatus> */
    public const BOARD_COLUMNS = [
        OrderStatus::Started,
        OrderStatus::Open,
        OrderStatus::Preparing,
        OrderStatus::Ready,
        OrderStatus::InTransit,
    ];

    /** @var array<int, OrderStatus> */
    public const FINISHED_STATUSES = [
        OrderStatus::Delivered,
        OrderStatus::Finished,
    ];

    private const RECENT_FINISHED_LIMIT = 20;

    private const FILTER_PREFERENCE_KEY = 'kitchen.filters';

    /** @var array<int, string> */
    private const PERSISTED_FILTER_PROPERTIES = [
        'quickFilter', 'deliveryUserFilter', 'onlyLate',
        'periodFrom', 'periodUntil', 'showCancelled', 'productionLineFilter',
    ];

    public string $quickFilter = 'all';

    public string $search = '';

    public ?int $deliveryUserFilter = null;

    public ?string $periodFrom = null;

    public ?string $periodUntil = null;

    public bool $showCancelled = false;

    public bool $onlyLate = false;

    #[Url(as: 'linha')]
    public ?int $productionLineFilter = null;

    public ?string $lastRefreshedAt = null;

    public function mount(): void
    {
        $this->touchLastRefreshedAt();
        $this->loadFilterPreferences(
            self::FILTER_PREFERENCE_KEY,
            self::PERSISTED_FILTER_PROPERTIES,
            skipIfAlreadySet: ['productionLineFilter'],
        );
    }

    /**
     * @param  mixed  $value
     */
    public function updated(string $property, $value): void
    {
        if (in_array($property, self::PERSISTED_FILTER_PROPERTIES, true)) {
            $this->persistFilterPreferences(self::FILTER_PREFERENCE_KEY, self::PERSISTED_FILTER_PROPERTIES);
        }
    }

    public function refreshBoard(): void
    {
        $this->touchLastRefreshedAt();
    }

    private function touchLastRefreshedAt(): void
    {
        $this->lastRefreshedAt = now()->format('H:i:s');
    }

    #[Computed]
    public function ordersByStatus(): Collection
    {
        $statusFilter = $this->statusFilterValue();
        $activeStatuses = $statusFilter ? [$statusFilter] : self::BOARD_COLUMNS;

        $active = $this->quickFilter === 'finished'
            ? collect()
            : $this->filteredQuery()->whereIn('status', $activeStatuses)->orderBy('opened_at')->get();

        $finished = $this->filteredQuery()
            ->whereIn('status', self::FINISHED_STATUSES)
            ->where('opened_at', '>=', $this->activeShiftStart())
            ->orderByDesc('opened_at')
            ->limit($this->quickFilter === 'finished' ? 100 : self::RECENT_FINISHED_LIMIT)
            ->get();

        $all = $this->resolveItemDisplayNames($active->concat($finished));

        if ($this->onlyLate) {
            $all = $all->filter(fn (Order $order): bool => $this->urgencyFor($order) === OrderUrgencyLevel::Late);
        }

        return $all->groupBy(fn (Order $order): string => $order->status->value);
    }

    #[Computed]
    public function cancelledOrders(): Collection
    {
        if (! $this->showCancelled) {
            return collect();
        }

        $orders = $this->filteredQuery()
            ->where('status', OrderStatus::Cancelled)
            ->where('opened_at', '>=', $this->activeShiftStart())
            ->orderByDesc('opened_at')
            ->limit(self::RECENT_FINISHED_LIMIT)
            ->get();

        return $this->resolveItemDisplayNames($orders);
    }

    #[Computed]
    public function deliveryPersonnelOptions(): Collection
    {
        return User::deliveryPersonnel()->orderBy('name')->pluck('name', 'id');
    }

    /**
     * Colunas do board. O tenant que não usa a etapa "Em Transporte"
     * (config `uses_in_transit_stage`) nunca tem pedidos nesse status, então a
     * coluna "Em Entrega" some.
     *
     * @return array<int, OrderStatus>
     */
    public function boardColumns(): array
    {
        if (CurrentTenant::get()?->uses_in_transit_stage ?? true) {
            return self::BOARD_COLUMNS;
        }

        return array_values(array_filter(
            self::BOARD_COLUMNS,
            fn (OrderStatus $status): bool => $status !== OrderStatus::InTransit,
        ));
    }

    /**
     * O filtro "Todos entregadores" e a coluna de entregador nos cards só fazem
     * sentido quando o tenant atribui entregador aos pedidos.
     */
    public function showsDeliveryPersonnelFilter(): bool
    {
        return CurrentTenant::get()?->assigns_delivery_couriers ?? true;
    }

    #[Computed]
    public function productionLines(): Collection
    {
        return ProductionLine::orderBy('name')->pluck('name', 'id');
    }

    /**
     * Resolve o nome de exibição de cada item em lote (evita N+1): itens
     * combo (flavors preenchido) mostram todos os sabores escolhidos, não só
     * o produto "âncora" gravado em product_id. Também resolve os
     * adicionais de cada item (RN-48), indicando o sabor-alvo quando
     * aplicável, pra cozinha saber exatamente onde colocar o ingrediente.
     */
    private function resolveItemDisplayNames(Collection $orders): Collection
    {
        $allItems = $orders->flatMap(fn (Order $order) => $order->items);

        $flavorIds = $allItems->pluck('flavors')->filter()->flatten()->unique()->values();

        $flavorProducts = $flavorIds->isEmpty()
            ? collect()
            : Product::withTrashed()->whereIn('id', $flavorIds)->get(['id', 'name'])->keyBy('id');

        $addonIds = $allItems->flatMap(fn (OrderItem $item) => $item->addons ?? [])->pluck('addon_id')->unique()->values();

        $addonNames = $addonIds->isEmpty()
            ? collect()
            : Addon::withTrashed()->whereIn('id', $addonIds)->get(['id', 'name'])->keyBy('id');

        $giftIds = $allItems->flatMap(fn (OrderItem $item) => $item->gifts ?? [])->pluck('gift_product_id')->unique()->values();

        $giftNames = $giftIds->isEmpty()
            ? collect()
            : Product::withTrashed()->whereIn('id', $giftIds)->get(['id', 'name'])->keyBy('id');

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $item->displayName = $item->flavors
                    ? collect($item->flavors)->map(fn ($id) => $flavorProducts->get($id)?->name)->filter()->implode(' / ')
                    : ($item->product?->name ?? 'Item removido');

                $item->addonsDisplay = collect($item->addons ?? [])->map(function (array $selection) use ($addonNames, $flavorProducts) {
                    $name = $addonNames->get($selection['addon_id'])?->name ?? 'Adicional removido';
                    $target = $selection['target'] !== null ? ($flavorProducts->get($selection['target'])?->name ?? 'sabor removido') : 'produto inteiro';

                    return "{$selection['quantity']}x {$name} ({$target})";
                })->all();

                $item->giftsDisplay = collect($item->gifts ?? [])->map(function (array $gift) use ($giftNames, $item) {
                    $name = $giftNames->get($gift['gift_product_id'])?->name ?? 'Brinde removido';

                    return ($gift['accepted'] ?? false) === true
                        ? GiftLineLabel::accepted($gift, $item->quantity, $name)
                        : "🎁 {$name} — recusado pelo cliente";
                })->all();
            }
        }

        return $orders;
    }

    public function minutesInStageFor(Order $order): ?int
    {
        return $order->minutesInCurrentStage();
    }

    public function urgencyFor(Order $order): OrderUrgencyLevel
    {
        $tenant = CurrentTenant::get();

        return app(OrderUrgencyResolver::class)->resolve(
            $this->minutesInStageFor($order),
            $tenant?->order_attention_after_minutes ?? 15,
            $tenant?->order_late_after_minutes ?? 30,
        );
    }

    public function primaryActionName(Order $order): string
    {
        return match (true) {
            $order->status === OrderStatus::Ready && $order->assignsDeliveryCourier() => 'dispatch',
            $order->status === OrderStatus::InTransit => 'markDelivered',
            $order->status->canAdvanceGenerically() => 'advance',
            default => 'viewDetails',
        };
    }

    public function primaryActionLabel(Order $order): string
    {
        return match (true) {
            $order->status === OrderStatus::Started => 'Aceitar',
            $order->status === OrderStatus::Open => 'Iniciar preparo',
            $order->status === OrderStatus::Preparing => 'Marcar pronto',
            $order->status === OrderStatus::Ready && $order->assignsDeliveryCourier() => 'Despachar',
            $order->status === OrderStatus::Ready && $order->usesInTransitStage() => 'Saída para entrega',
            $order->status === OrderStatus::Ready => 'Finalizar',
            $order->status === OrderStatus::InTransit => 'Confirmar entrega',
            default => 'Ver detalhes',
        };
    }

    public function cancelLabel(Order $order): string
    {
        return $order->status === OrderStatus::Started ? 'Rejeitar' : 'Cancelar';
    }

    /**
     * Rótulos de coluna do board (seção 6 da spec) — diferentes dos rótulos
     * comerciais de OrderStatus::label() usados no restante do painel.
     */
    public function columnLabel(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Started => 'Novos',
            OrderStatus::Open => 'Aceitos',
            OrderStatus::Preparing => 'Preparando',
            OrderStatus::Ready => 'Prontos',
            OrderStatus::InTransit => 'Em Entrega',
            OrderStatus::Delivered, OrderStatus::Finished => 'Finalizados',
            OrderStatus::Cancelled => 'Cancelados',
        };
    }

    /**
     * Classe Tailwind completa (não fragmento) do ponto colorido do
     * cabeçalho de cada coluna (seção 9 do documento de redesenho) — usada
     * só no indicador, não no fundo da coluna inteira. Precisa ser a classe
     * inteira em texto literal aqui (não montada por interpolação no Blade),
     * porque o Tailwind v4 só detecta classes por escaneamento estático de
     * texto — `bg-{$var}-500` no Blade nunca seria encontrado.
     */
    public function columnDotClass(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Started => 'bg-slate-400',
            OrderStatus::Open => 'bg-rf-teal-500',
            OrderStatus::Preparing => 'bg-rf-amber-300',
            OrderStatus::Ready => 'bg-rf-orange-600',
            OrderStatus::InTransit => 'bg-rf-teal-300',
            OrderStatus::Delivered, OrderStatus::Finished => 'bg-green-600',
            OrderStatus::Cancelled => 'bg-red-600',
        };
    }

    public function advanceAction(): Action
    {
        return OrderStatusActions::advance();
    }

    public function dispatchAction(): Action
    {
        return OrderStatusActions::dispatch();
    }

    public function reassignDeliveryAction(): Action
    {
        return OrderStatusActions::reassignDelivery();
    }

    public function cancelAction(): Action
    {
        return OrderStatusActions::cancel();
    }

    public function markDeliveredAction(): Action
    {
        return OrderStatusActions::markDelivered();
    }

    public function deliveryLinkAction(): Action
    {
        return OrderStatusActions::deliveryLink();
    }

    public function viewDetailsAction(): Action
    {
        return OrderStatusActions::viewDetails();
    }

    private function filteredQuery(): Builder
    {
        return Order::query()
            ->select([
                'id', 'tenant_id', 'client_id', 'delivery_option_id', 'assigned_delivery_user_id',
                'status', 'origin', 'grand_total', 'opened_at', 'accepted_at', 'preparing_at',
                'ready_at', 'in_transit_at', 'delivered_at', 'finished_at', 'created_at',
            ])
            ->with([
                'client:id,name,phone',
                'assignedDeliveryUser:id,name',
                'items:id,order_id,product_id,quantity,flavors,addons,gifts',
                'items.product:id,name,category_id',
                'items.product.category:id,name',
            ])
            ->when($this->search !== '', fn (Builder $query) => $this->applySearch($query))
            ->when($this->deliveryUserFilter && $this->showsDeliveryPersonnelFilter(), fn (Builder $query) => $query->where('assigned_delivery_user_id', $this->deliveryUserFilter))
            ->when($this->periodFrom, fn (Builder $query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($this->periodUntil, fn (Builder $query, string $until) => $query->whereDate('created_at', '<=', $until))
            ->when($this->fulfillmentFilterValue(), fn (Builder $query, string $type) => $this->applyFulfillmentFilter($query, $type))
            ->when($this->productionLineFilter, fn (Builder $query, int $lineId) => $query->whereHas(
                'items.product',
                fn (Builder $q) => $q->whereIn('category_id', $this->categoryIdsForProductionLine($lineId)),
            ));
    }

    /**
     * Categorias associadas a uma linha de produção, incluindo todas as
     * subcategorias descendentes — uma linha vinculada a uma categoria pai
     * (ex.: "Pizzas") precisa casar com produtos das categorias filhas
     * (ex.: "Pizzas Salgadas"/"Pizzas Doces"), não só com produtos
     * cadastrados diretamente na categoria pai.
     *
     * @return array<int, int>
     */
    private function categoryIdsForProductionLine(int $lineId): array
    {
        $line = ProductionLine::with('categories:id')->find($lineId);

        if (! $line || $line->categories->isEmpty()) {
            return [];
        }

        $childrenByParent = Category::query()->get(['id', 'parent_id'])->groupBy('parent_id');

        $categoryIds = collect();
        $pending = $line->categories->pluck('id')->all();

        while ($pending) {
            $id = array_pop($pending);
            $categoryIds->push($id);

            foreach ($childrenByParent->get($id, collect()) as $child) {
                $pending[] = $child->id;
            }
        }

        return $categoryIds->unique()->values()->all();
    }

    private function applySearch(Builder $query): Builder
    {
        $term = trim($this->search);

        return $query->where(function (Builder $inner) use ($term): void {
            if (is_numeric($term)) {
                $inner->orWhere('id', (int) $term);
            }

            $inner->orWhereHas('client', function (Builder $clientQuery) use ($term): void {
                $clientQuery->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            });
        });
    }

    private function statusFilterValue(): ?OrderStatus
    {
        return match ($this->quickFilter) {
            'pending' => OrderStatus::Started,
            'preparing' => OrderStatus::Preparing,
            'ready' => OrderStatus::Ready,
            'in_transit' => OrderStatus::InTransit,
            default => null,
        };
    }

    private function fulfillmentFilterValue(): ?string
    {
        return in_array($this->quickFilter, ['delivery', 'pickup', 'dine_in'], true) ? $this->quickFilter : null;
    }

    private function applyFulfillmentFilter(Builder $query, string $type): Builder
    {
        return match ($type) {
            'delivery' => $query->whereNotNull('delivery_option_id'),
            'dine_in' => $query->where('origin', OrderOrigin::Table),
            'pickup' => $query->whereNull('delivery_option_id')->where('origin', '!=', OrderOrigin::Table),
            default => $query,
        };
    }

    private function activeShiftStart(): Carbon
    {
        return app(ResolveActiveShiftStart::class)();
    }
}
