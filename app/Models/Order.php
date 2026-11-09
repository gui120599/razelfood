<?php

namespace App\Models;

use App\Enums\CancellationReason;
use App\Enums\OrderFulfillmentType;
use App\Enums\OrderOrigin;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\SaleStatus;
use App\Models\Concerns\TenantScopedModel;
use App\Support\CurrentTenant;
use App\Support\Sales\PdvCutoff;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends TenantScopedModel
{
    protected $fillable = [
        'tenant_id',
        'order_number',
        'public_token',
        'client_id',
        'delivery_option_id',
        'delivery_zone_id',
        'assigned_delivery_user_id',
        'items_total',
        'discount_total',
        'delivery_fee',
        'grand_total',
        'status',
        'cancellation_reason',
        'cancelled_by_user_id',
        'delivery_address',
        'delivery_zip_code',
        'delivery_street',
        'delivery_number',
        'delivery_complement',
        'delivery_neighborhood',
        'delivery_city',
        'delivery_state',
        'is_unlisted_neighborhood',
        'notes',
        'origin',
        'opened_at',
        'accepted_at',
        'preparing_at',
        'ready_at',
        'in_transit_at',
        'delivered_at',
        'finished_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'order_number' => 'integer',
            'items_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'status' => OrderStatus::class,
            'cancellation_reason' => CancellationReason::class,
            'is_unlisted_neighborhood' => 'boolean',
            'origin' => OrderOrigin::class,
            'opened_at' => 'datetime',
            'accepted_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'in_transit_at' => 'datetime',
            'delivered_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (blank($order->public_token)) {
                $order->public_token = (string) Str::ulid();
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Situação de pagamento derivada (RN-PED-03): Paga = todos os itens estão em vendas finalizadas
     * (`sale_id` do pedido marca o caso comum, pedido cobrado por inteiro numa venda); Parcial =
     * só alguns. Item reservado em rascunho ainda é "a receber". `CancelSale` desvincula os itens,
     * então o pedido volta a "a receber". Não altera nem depende de `OrderStatus`.
     */
    public function paymentStatus(): OrderPaymentStatus
    {
        if ($this->sale_id !== null) {
            return OrderPaymentStatus::Paid;
        }

        // Listas usam `withPaymentProgress()` e evitam uma consulta por linha.
        if (array_key_exists('items_count', $this->attributes) && array_key_exists('sold_items_count', $this->attributes)) {
            return $this->statusFromProgress((int) $this->attributes['items_count'], (int) $this->attributes['sold_items_count']);
        }

        $progress = OrderItem::query()
            ->where('order_items.order_id', $this->id)
            ->leftJoin('sales', 'sales.id', '=', 'order_items.sale_id')
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(sales.status = ?), 0) as sold', [SaleStatus::Finalized->value])
            ->first();

        return $this->statusFromProgress((int) ($progress->total ?? 0), (int) ($progress->sold ?? 0));
    }

    private function statusFromProgress(int $total, int $sold): OrderPaymentStatus
    {
        return match (true) {
            $total > 0 && $sold === $total => OrderPaymentStatus::Paid,
            $sold > 0 => OrderPaymentStatus::PartiallyPaid,
            default => OrderPaymentStatus::Pending,
        };
    }

    /**
     * Carrega `items_count` e `sold_items_count` (itens em venda finalizada) para
     * `paymentStatus()` não consultar o banco por linha numa lista.
     */
    public function scopeWithPaymentProgress(Builder $query): Builder
    {
        return $query->withCount([
            'items',
            'items as sold_items_count' => fn (Builder $items) => $items->whereIn(
                'order_items.sale_id',
                Sale::query()->withoutGlobalScopes()->select('id')->where('status', SaleStatus::Finalized->value),
            ),
        ]);
    }

    /**
     * Algum item do pedido está numa venda, aberta (reservado) ou finalizada? Então o pedido não
     * pode ser editado nem cancelado: tire os itens da venda ou cancele a venda. Consulta o banco
     * (chame sobre o pedido já travado).
     */
    public function hasItemsInSale(): bool
    {
        return $this->sale_id !== null
            || OrderItem::query()->where('order_id', $this->id)->whereNotNull('sale_id')->exists();
    }

    /**
     * Pedidos elegíveis a "Contas a fechar": com ao menos um item livre (fora de qualquer venda), já aceitos (nem
     * "Iniciado" nem cancelado) e criados a partir da ativação do PDV do
     * tenant (data de corte). Sem data de corte (feature desligada) não há
     * nenhum elegível.
     */
    public function scopeAwaitingSale(Builder $query): Builder
    {
        $tenant = CurrentTenant::get();
        $cutoff = $tenant ? PdvCutoff::for($tenant) : null;

        return $query
            ->whereExists(fn ($items) => $items->selectRaw('1')->from('order_items')
                ->whereColumn('order_items.order_id', 'orders.id')->whereNull('order_items.sale_id'))
            ->whereNotIn('orders.status', [OrderStatus::Started->value, OrderStatus::Cancelled->value])
            ->when(
                $cutoff === null,
                fn (Builder $q): Builder => $q->whereRaw('1 = 0'),
                fn (Builder $q): Builder => $q->where('orders.created_at', '>=', $cutoff),
            );
    }

    public function deliveryOption(): BelongsTo
    {
        return $this->belongsTo(DeliveryOption::class);
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function assignedDeliveryUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_delivery_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /**
     * Número do pedido para o estabelecimento (comanda, painel). Cai no
     * id interno enquanto o pedido não tiver número (pedidos legados antes
     * do backfill, ou fixtures de teste que não passam por CreateOrderFromCart).
     */
    public function displayNumber(): string
    {
        return '#'.($this->order_number ?? $this->id);
    }

    /**
     * Pedidos feitos (opened_at) dentro do intervalo — recorte padrão dos
     * relatórios operacionais (RF-31).
     */
    public function scopeOpenedBetween(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->whereBetween('opened_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()]);
    }

    /**
     * Pedidos efetivamente entregues no intervalo (delivered_at) — recorte do
     * relatório de entregas por entregador.
     */
    public function scopeDeliveredBetween(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()]);
    }

    public function currentStageStartedAt(): ?Carbon
    {
        return $this->{$this->status->timestampColumn()};
    }

    public function minutesInCurrentStage(): ?int
    {
        return $this->currentStageStartedAt()?->diffInMinutes(now());
    }

    /**
     * Deriva o tipo de atendimento a partir do que já existe (delivery_option_id/origin)
     * — não é uma coluna nova, é só uma leitura de conveniência para badges/filtros.
     * Uma DeliveryOption com requires_address=false (ex.: "Retirada", "Comer no
     * Local") não conta como entrega, mesmo com delivery_option_id preenchido.
     */
    public function fulfillmentType(): OrderFulfillmentType
    {
        return match (true) {
            $this->delivery_option_id !== null && ($this->deliveryOption?->requires_address ?? true) => OrderFulfillmentType::Delivery,
            $this->origin === OrderOrigin::Table => OrderFulfillmentType::DineIn,
            default => OrderFulfillmentType::Pickup,
        };
    }

    /**
     * Atalho pra "esta opção de entrega exige logística de entrega de
     * verdade" (não é retirada/consumo no local) — usado pra decidir se o
     * pedido precisa passar por "Em Transporte" e se as ações de despacho
     * (dispatch, link de entrega) fazem sentido pra ele.
     */
    public function requiresDelivery(): bool
    {
        return $this->fulfillmentType() === OrderFulfillmentType::Delivery;
    }

    /**
     * Pedido de entrega que, para este tenant, ainda passa pela etapa
     * "Em Transporte" antes de ser finalizado (config `uses_in_transit_stage`).
     * Desligado: "Pronto" avança direto para "Finalizado", igual à retirada.
     */
    public function usesInTransitStage(): bool
    {
        return $this->requiresDelivery()
            && (CurrentTenant::get()?->uses_in_transit_stage ?? true);
    }

    /**
     * Pedido de entrega que, para este tenant, exige escolher um entregador ao
     * ser despachado (config `assigns_delivery_couriers`). Só faz sentido quando
     * o pedido passa pela etapa "Em Transporte".
     */
    public function assignsDeliveryCourier(): bool
    {
        return $this->usesInTransitStage()
            && (CurrentTenant::get()?->assigns_delivery_couriers ?? true);
    }

    /**
     * O link/QR de entrega (confirmação pelo celular do entregador) só faz sentido para pedido de
     * entrega que passa por "Em Transporte" e já está pronto ou a caminho. Mesma regra da Action
     * `deliveryLink` e do menu do card da Central de Pedidos.
     */
    public function allowsDeliveryLink(): bool
    {
        return $this->usesInTransitStage()
            && in_array($this->status, [OrderStatus::Ready, OrderStatus::InTransit], true);
    }

    /**
     * Trocar o entregador exige que o tenant atribua entregador e que o pedido já tenha um
     * (ou esteja pronto/a caminho, quando ainda pode receber). Mesma regra da Action `reassignDelivery`.
     */
    public function allowsCourierReassignment(): bool
    {
        if (! (CurrentTenant::get()?->assigns_delivery_couriers ?? true)) {
            return false;
        }

        return $this->assigned_delivery_user_id !== null
            || in_array($this->status, [OrderStatus::Ready, OrderStatus::InTransit], true);
    }

    /**
     * O pedido pode ser aberto para edição na tela de atendimento por este usuário: conteúdo ainda editável,
     * não cobrado numa venda e, do estágio "Pronto" em diante, com a permissão avançada. Mesma regra do
     * `mount()` do AttendOrder (que responde 403 nos outros casos) e do atalho "Editar" da lista de pedidos.
     * Só olha `sale_id` (já carregado); item avulso lançado em venda é barrado ao salvar.
     */
    public function isEditableInAttendanceBy(?User $user): bool
    {
        if ($this->sale_id !== null || ! $this->status->isEditableContentWise()) {
            return false;
        }

        return ! $this->status->requiresAdvancedPermissionToEdit()
            || ($user?->can('edit_order_advanced_status') ?? false);
    }

    /**
     * Quem confirma a entrega (Em Transporte → Entregue): o Entregador (`mark_order_delivered`) e, quando o tenant
     * não atribui entregador, também quem gerencia pedidos — senão o Atendente não fecharia o pedido.
     * Mesma regra da Action `markDelivered` e do botão do card.
     */
    public function canConfirmDeliveryBy(?User $user): bool
    {
        return (bool) $user?->can('mark_order_delivered')
            || (! (CurrentTenant::get()?->assigns_delivery_couriers ?? true) && (bool) $user?->can('manage_order_status'));
    }

    /**
     * Link público de acompanhamento (o mesmo enviado ao cliente no WhatsApp). Usa o token opaco,
     * nunca o id; null enquanto o pedido não tem token.
     */
    public function trackingUrl(): ?string
    {
        return blank($this->public_token) ? null : route('order.tracking', ['order' => $this->public_token]);
    }
}
