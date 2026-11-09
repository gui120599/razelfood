<?php

namespace App\Filament\Tenant\Pages;

use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\OrderUrgencyLevel;
use App\Filament\Tenant\Pages\Orders\AttendOrder;
use App\Filament\Tenant\Support\OrderStatusActions;
use App\Models\Addon;
use App\Models\Category;
use App\Models\FlavorQuantityOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductionLine;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use App\Support\Fiscal\FlavorFraction;
use App\Support\Orders\GiftLineLabel;
use App\Support\Orders\OrderUrgencyResolver;
use App\Support\Orders\ResolveActiveShiftStart;
use App\Support\Preferences\PersistsFilterPreferences;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
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

    /** Cancelados do turno exibidos na faixa de cancelados. */
    private const RECENT_FINISHED_LIMIT = 20;

    /** Coluna "Finalizados": limite normal e depois de "Carregar mais finalizados". */
    private const FINISHED_LIMIT = 50;

    private const FINISHED_LIMIT_EXPANDED = 200;

    private const COLUMNS_PREFERENCE_KEY = 'kitchen.columns';

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

    /**
     * Dia de abertura cujos pedidos finalizados a última coluna mostra. Enquanto o operador não escolhe,
     * acompanha o turno atual (atualizado a cada poll, então vira sozinho na troca de turno).
     */
    public ?string $finishedDate = null;

    public bool $finishedDateChosen = false;

    /** Mostra também os pedidos em andamento abertos antes do turno atual (presos em alguma etapa). */
    public bool $showOutOfShift = false;

    public bool $finishedExpanded = false;

    /**
     * Impressão digital do estado do board. Enquanto não muda, o poll devolve resposta vazia
     * (ver refreshBoard()): sem consulta de cards nem diff de DOM.
     */
    #[Locked]
    public string $boardSignature = '';

    /**
     * Maior id entre os pedidos "Novos" do cardápio/mesa: o alerta sonoro toca quando surge um id maior. Um marcador
     * que só cresce não se perde quando a fila encolhe por uma ação local (confirmar, cancelar) no mesmo intervalo
     * do poll, o que acontece com uma simples contagem.
     */
    #[Locked]
    public int $latestIncomingId = 0;

    /** Havia mais finalizados do que o limite (preenchido ao montar `ordersByStatus`). */
    private bool $finishedHasMore = false;

    private ?Carbon $shiftStartCache = null;

    /**
     * Colunas que o usuário escolheu esconder (valores de OrderStatus; "delivered" é a coluna Finalizados).
     * Preferência por usuário, vale em qualquer aparelho.
     *
     * @var array<int, string>
     */
    public array $hiddenColumns = [];

    public function mount(): void
    {
        $this->finishedDate = $this->activeShiftStart()->toDateString();
        $this->hiddenColumns = $this->sanitizeHiddenColumns(UserPreference::valueFor(auth()->user(), self::COLUMNS_PREFERENCE_KEY)['hidden'] ?? []);
        $this->loadFilterPreferences(
            self::FILTER_PREFERENCE_KEY,
            self::PERSISTED_FILTER_PROPERTIES,
            skipIfAlreadySet: ['productionLineFilter'],
        );

        // Depois das preferências (showCancelled entra na assinatura): o primeiro poll não repete o render.
        $this->boardSignature = $this->currentBoardSignature();
        $this->latestIncomingId = $this->newestIncomingOrderId();
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

    /**
     * Alvo do `wire:poll`. Só renderiza de novo quando a assinatura do board muda (ou o turno virou); no estado
     * estacionário a resposta é vazia. Se a fila de entrada cresceu, avisa o navegador (`novo-pedido`, que
     * toca o som se o aparelho ligou).
     */
    public function refreshBoard(): void
    {
        $dateMoved = false;

        if (! $this->finishedDateChosen) {
            $current = $this->activeShiftStart()->toDateString();
            $dateMoved = $current !== $this->finishedDate;
            $this->finishedDate = $current;
        }

        $signature = $this->currentBoardSignature();

        if (! $dateMoved && $signature === $this->boardSignature) {
            $this->skipRender();

            return;
        }

        $this->boardSignature = $signature;
        unset($this->ordersByStatus, $this->cancelledOrders, $this->outOfShiftCounts, $this->outOfShiftTotal);

        $latest = $this->newestIncomingOrderId();

        if ($latest > $this->latestIncomingId) {
            $this->dispatch('novo-pedido');
        }

        $this->latestIncomingId = max($latest, $this->latestIncomingId);
    }

    /**
     * Contagem e último `updated_at` por status, mais o início do turno e o minuto corrente. A contagem é
     * indispensável: transições gravadas por query builder não mexem em `updated_at`, mas movem a contagem
     * entre os grupos. O turno e o minuto fazem o board se redesenhar quando um pedido passa do prazo
     * (tempo no status) ou sai do turno sem nenhuma mudança de status. Os filtros de tela ficam de fora:
     * mudá-los já dispara uma requisição própria.
     */
    private function currentBoardSignature(): string
    {
        $flow = array_map(fn (OrderStatus $status): string => $status->value, $this->boardColumns());

        if ($this->showCancelled) {
            $flow[] = OrderStatus::Cancelled->value;
        }

        $finished = array_map(fn (OrderStatus $status): string => $status->value, self::FINISHED_STATUSES);
        $shiftStart = $this->activeShiftStart();

        // Os finalizados entram só a partir do turno atual: o histórico inteiro do tenant não pode ser varrido
        // a cada poll (10 s por tablet).
        return Order::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('status', $flow)
                ->orWhere(fn (Builder $done) => $done
                    ->whereIn('status', $finished)
                    ->whereRaw('COALESCE(finished_at, delivered_at, opened_at) >= ?', [$shiftStart])))
            ->selectRaw('status, COUNT(*) AS total, COALESCE(MAX(updated_at), 0) AS latest')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(fn ($row): string => "{$row->status->value}:{$row->total}:{$row->latest}")
            ->push('shift:'.$shiftStart->timestamp)
            ->push('minute:'.now()->format('YmdHi'))
            ->implode('|');
    }

    /**
     * Maior id entre os pedidos aguardando confirmação (coluna "Novos"). Pedido lançado por atendente não conta:
     * quem o criou já sabe dele, e o beep tocaria a cada pedido digitado (o alerta é para o que chega de fora).
     */
    private function newestIncomingOrderId(): int
    {
        return (int) Order::query()
            ->where('status', OrderStatus::Started)
            ->where('origin', '!=', OrderOrigin::Staff)
            ->max('id');
    }

    #[Computed]
    public function ordersByStatus(): Collection
    {
        $visible = array_map(fn (OrderStatus $status): string => $status->value, $this->visibleColumns());
        $statusFilter = $this->statusFilterValue();
        $activeStatuses = array_values(array_filter(
            $statusFilter ? [$statusFilter] : self::BOARD_COLUMNS,
            fn (OrderStatus $status): bool => in_array($status->value, $visible, true),
        ));

        // Só o turno atual entra nas colunas em andamento: o que ficou preso de turnos anteriores vira o
        // contador "N pedidos de turnos anteriores". Um período informado substitui essa janela.
        $active = $this->quickFilter === 'finished' || $activeStatuses === []
            ? collect()
            : $this->filteredQuery()
                ->whereIn('status', $activeStatuses)
                ->when(
                    ! $this->periodFrom && ! $this->periodUntil && ! $this->showOutOfShift,
                    fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                        ->where('opened_at', '>=', $this->activeShiftStart())
                        ->orWhereNull('opened_at')),
                )
                ->orderBy('opened_at')
                ->get();

        $finished = collect();
        $this->finishedHasMore = false;

        if (in_array(OrderStatus::Delivered->value, $visible, true)) {
            [$windowStart, $windowEnd] = $this->finishedWindow();
            $limit = $this->finishedLimit();

            // Um único carimbo de referência por pedido (finalização, senão entrega, senão abertura): cada
            // pedido pertence a exatamente uma janela, mesmo que existam os dois carimbos.
            $rows = $this->filteredQuery()
                ->whereIn('status', self::FINISHED_STATUSES)
                ->whereRaw('COALESCE(finished_at, delivered_at, opened_at) >= ?', [$windowStart])
                ->when($windowEnd !== null, fn (Builder $query) => $query->whereRaw('COALESCE(finished_at, delivered_at, opened_at) < ?', [$windowEnd]))
                ->orderByDesc('id')
                ->limit($limit + 1)
                ->get();

            $this->finishedHasMore = $rows->count() > $limit;
            $finished = $rows->take($limit);
        }

        $all = $this->resolveItemDisplayNames($active->concat($finished));

        if ($this->onlyLate) {
            $all = $all->filter(fn (Order $order): bool => $this->urgencyFor($order) === OrderUrgencyLevel::Late);
        }

        return $all->groupBy(fn (Order $order): string => $order->status->value);
    }

    /**
     * Pedidos em andamento abertos antes do turno atual, por status. Só a contagem: a listagem entra nas
     * colunas se o operador clicar em "mostrar". Ignora os filtros da tela de propósito (o aviso é do tenant).
     *
     * @return array<string, int>
     */
    #[Computed]
    public function outOfShiftCounts(): array
    {
        // Com período informado a janela do turno não vale: nada fica "escondido por turno".
        if ($this->periodFrom || $this->periodUntil) {
            return [];
        }

        return Order::query()
            ->whereIn('status', collect($this->boardColumns())->map(fn (OrderStatus $status): string => $status->value)->all())
            ->where('opened_at', '<', $this->activeShiftStart())
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    #[Computed]
    public function outOfShiftTotal(): int
    {
        return array_sum($this->outOfShiftCounts);
    }

    public function toggleOutOfShift(): void
    {
        $this->showOutOfShift = ! $this->showOutOfShift;
        unset($this->ordersByStatus);
    }

    public function expandFinished(): void
    {
        $this->finishedExpanded = true;
        unset($this->ordersByStatus);
    }

    public function updatedFinishedDate(): void
    {
        $this->finishedDateChosen = $this->parseFinishedDate() !== null;
        $this->finishedExpanded = false;
        unset($this->ordersByStatus);
    }

    /** Data escolhida no seletor, só se for um 'Y-m-d' válido (a propriedade é pública e vem do navegador). */
    private function parseFinishedDate(): ?Carbon
    {
        return app(ResolveActiveShiftStart::class)->parseOpeningDay($this->finishedDate);
    }

    /**
     * Janela da coluna Finalizados: sem escolha do operador, vale o turno atual (do início dele em diante,
     * como o corte das colunas em andamento); com data escolhida, o dia operacional daquela abertura.
     *
     * @return array{0: Carbon, 1: ?Carbon} [início, fim) — fim nulo = sem limite
     */
    private function finishedWindow(): array
    {
        $chosen = $this->finishedDateChosen ? $this->parseFinishedDate() : null;

        if ($chosen === null) {
            return [$this->activeShiftStart(), null];
        }

        return app(ResolveActiveShiftStart::class)->windowForOpeningDay($chosen);
    }

    private function finishedLimit(): int
    {
        return $this->finishedExpanded ? self::FINISHED_LIMIT_EXPANDED : self::FINISHED_LIMIT;
    }

    /** "Carregar mais finalizados" só aparece quando de fato há mais pedidos além do limite normal. */
    public function canLoadMoreFinished(): bool
    {
        return ! $this->finishedExpanded && $this->finishedHasMore;
    }

    /** Mesmo depois de "carregar mais" ainda havia pedidos: a coluna avisa e aponta o histórico. */
    public function finishedIsTruncated(): bool
    {
        return $this->finishedExpanded && $this->finishedHasMore;
    }

    /**
     * Todas as colunas possíveis, na ordem do board: as do fluxo e a última (Finalizados, marcada por Delivered).
     *
     * @return array<int, OrderStatus>
     */
    public function availableColumns(): array
    {
        return [...$this->boardColumns(), OrderStatus::Delivered];
    }

    /**
     * Colunas que a tela renderiza: as disponíveis menos as que o usuário escondeu. A coluna que o filtro
     * rápido seleciona ("Prontos", "Finalizados"…) nunca fica escondida, e se tudo estiver escondido (por
     * exemplo, só havia "Em Entrega" visível e o tenant desligou esse estágio) volta tudo.
     *
     * @return array<int, OrderStatus>
     */
    public function visibleColumns(): array
    {
        $focused = $this->quickFilter === 'finished'
            ? OrderStatus::Delivered->value
            : $this->statusFilterValue()?->value;

        $visible = array_values(array_filter(
            $this->availableColumns(),
            fn (OrderStatus $status): bool => $status->value === $focused || ! in_array($status->value, $this->hiddenColumns, true),
        ));

        return $visible === [] ? $this->availableColumns() : $visible;
    }

    /**
     * Só valores de coluna existentes, sem repetição (a preferência salva e a propriedade pública vêm de fora).
     *
     * @return array<int, string>
     */
    private function sanitizeHiddenColumns(mixed $columns): array
    {
        if (! is_array($columns)) {
            return [];
        }

        $available = array_map(fn (OrderStatus $column): string => $column->value, $this->availableColumns());

        return array_values(array_unique(array_intersect(array_filter($columns, 'is_string'), $available)));
    }

    public function toggleColumn(string $status): void
    {
        $available = array_map(fn (OrderStatus $column): string => $column->value, $this->availableColumns());

        if (! in_array($status, $available, true)) {
            return;
        }

        $current = $this->sanitizeHiddenColumns($this->hiddenColumns);

        $hidden = in_array($status, $current, true)
            ? array_values(array_diff($current, [$status]))
            : [...$current, $status];

        if (array_diff($available, $hidden) === []) {
            Notification::make()->warning()->title('Mantenha ao menos uma coluna visível.')->send();

            return;
        }

        $this->hiddenColumns = $hidden;
        UserPreference::rememberFor(auth()->user(), self::COLUMNS_PREFERENCE_KEY, ['hidden' => $hidden]);
    }

    /**
     * Rótulo curto do status nos chips de navegação ("Iniciado", "Aberto"…), como na tela original; o
     * cabeçalho da coluna usa {@see columnLabel()}.
     */
    public function chipLabel(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Started => 'Iniciado',
            OrderStatus::Open => 'Aberto',
            OrderStatus::Preparing => 'Preparando',
            OrderStatus::Ready => 'Pronto',
            OrderStatus::InTransit => 'Em transporte',
            OrderStatus::Delivered, OrderStatus::Finished => 'Entregue',
            OrderStatus::Cancelled => 'Cancelado',
        };
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
            : Product::withTrashed()->whereIn('id', $flavorIds)->get(['id', 'name', 'category_id'])->keyBy('id');

        // Rateio configurado na categoria (ou na pai, quando herda): uma consulta para todas as pizzas do board.
        $flavorCategories = $flavorProducts->isEmpty()
            ? collect()
            : Category::withTrashed()
                ->with(['flavorQuantityOptions', 'parent.flavorQuantityOptions'])
                ->whereIn('id', $flavorProducts->pluck('category_id')->filter()->unique())
                ->get()
                ->keyBy('id');

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

                $item->flavorLines = $this->flavorSliceLines($item->flavors ?? [], $flavorProducts, $flavorCategories);

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

    /**
     * Uma linha por sabor com a fração ("MEIA Calabresa", "1/3 Marguerita"), para a cozinha ler o que
     * vai em cada pedaço. Vazio para item de um sabor só. O rateio vem da opção de quantidade da
     * categoria; sem opção cadastrada, divisão igual.
     *
     * @param  array<int, int|string>  $flavorIds
     * @param  Collection<int, Product>  $flavorProducts
     * @param  Collection<int, Category>  $categories
     * @return array<int, string>
     */
    private function flavorSliceLines(array $flavorIds, Collection $flavorProducts, Collection $categories): array
    {
        $count = count($flavorIds);

        if ($count < 2) {
            return [];
        }

        // O rateio é pela quantidade de sabores GRAVADA no item: um sabor removido de vez não desalinha as frações.
        $firstFlavor = collect($flavorIds)->map(fn ($id): ?Product => $flavorProducts->get((int) $id))->filter()->first();
        $category = $firstFlavor ? $categories->get($firstFlavor->category_id) : null;
        $shares = $category?->resolvedFlavorQuantityOptions()->firstWhere('flavor_count', $count)?->flavor_shares;

        if (! is_array($shares) || count($shares) !== $count) {
            $shares = FlavorQuantityOption::equalShares($count);
        }

        return collect($flavorIds)
            ->values()
            ->map(fn ($id, int $index): ?string => ($flavor = $flavorProducts->get((int) $id)) === null
                ? null
                : FlavorFraction::sliceLabel((float) $shares[$index]).' '.$flavor->name)
            ->filter()
            ->values()
            ->all();
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
            $order->status === OrderStatus::Started => 'Confirmar',
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
        // Antes de entrar em preparo o pedido é "rejeitado"; do preparo em diante, "cancelado".
        return in_array($order->status, [OrderStatus::Started, OrderStatus::Open], true) ? 'Rejeitar' : 'Cancelar';
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

    /**
     * Tom do cabeçalho da coluna (cor semântica do Filament, igual à tela de origem): fila neutra,
     * andamento em azul/âmbar, finalizado em verde.
     */
    public function columnTone(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Started => 'gray',
            OrderStatus::Open, OrderStatus::InTransit => 'info',
            OrderStatus::Preparing, OrderStatus::Ready => 'warning',
            OrderStatus::Delivered, OrderStatus::Finished => 'success',
            OrderStatus::Cancelled => 'danger',
        };
    }

    /** "Abrir no atendimento" (edição do pedido) só aparece para quem acessa a tela de atendimento. */
    public function canOpenAttendance(): bool
    {
        return AttendOrder::canAccess();
    }

    /**
     * "PDF do dia" e "PDF de entregas": ligam às impressões dos relatórios que o RazelFood já tem, para o TURNO
     * do seletor "Turno de" (`?shift=`: janela do dia operacional, que pode passar da meia-noite). Só aparecem para quem tem a feature de relatórios e a permissão da respectiva tela.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $day = fn (): string => $this->parseFinishedDate()?->toDateString() ?? $this->activeShiftStart()->toDateString();
        $hasReports = fn (): bool => CurrentTenant::get()?->hasFeature(FeatureKey::RELATORIOS) ?? false;

        return [
            Action::make('pdfDay')
                ->label('PDF do dia')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible(fn (): bool => $hasReports() && (auth()->user()?->can('View:Reports') ?? false))
                ->url(fn (): string => route('reports.orders.print', ['shift' => $day()]), shouldOpenInNewTab: true),

            Action::make('pdfDeliveries')
                ->label('PDF de entregas')
                ->icon(Heroicon::OutlinedTruck)
                ->color('gray')
                ->visible(fn (): bool => $hasReports() && (auth()->user()?->can('View:Deliveries') ?? false))
                ->url(fn (): string => route('reports.deliveries.print', ['shift' => $day()]), shouldOpenInNewTab: true),
        ];
    }

    /**
     * Iniciar o preparo (Aceito → Preparando) manda a comanda à impressora, se o aparelho ligou a opção. O
     * navegador imprime num iframe oculto: um window.open depois da ida e volta do Livewire já não conta
     * como gesto do usuário e seria barrado pelo bloqueador de pop-up.
     */
    public function advanceAction(): Action
    {
        return OrderStatusActions::advance(function (Order $order): void {
            if ($order->status === OrderStatus::Preparing) {
                $this->dispatch('imprimir-pedido', url: route('order.ticket', ['order' => $order->id]));
            }
        });
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
                'status', 'origin', 'grand_total', 'delivery_address', 'sale_id', 'public_token',
                'cancellation_reason', 'cancelled_by_user_id', 'cancelled_at',
                'opened_at', 'accepted_at', 'preparing_at',
                'ready_at', 'in_transit_at', 'delivered_at', 'finished_at', 'created_at',
            ])
            ->with([
                'client:id,name,phone',
                'deliveryOption:id,name,requires_address',
                'sale:id,number,status',
                'assignedDeliveryUser:id,name',
                'cancelledBy:id,name',
                'items:id,order_id,product_id,quantity,unit_price,addons_total,note,flavors,addons,gifts',
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

    /** Início do turno atual, calculado uma vez por request (várias consultas do board o usam). */
    private function activeShiftStart(): Carbon
    {
        return $this->shiftStartCache ??= app(ResolveActiveShiftStart::class)();
    }
}
