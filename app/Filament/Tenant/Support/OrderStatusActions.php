<?php

namespace App\Filament\Tenant\Support;

use App\Actions\Orders\AdvanceOrderStatus;
use App\Actions\Orders\AssignDeliveryUser;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\MarkOrderDelivered;
use App\Enums\CancellationReason;
use App\Enums\OrderStatus;
use App\Exceptions\OrderTransitionException;
use App\Models\Order;
use App\Models\User;
use App\Support\CurrentTenant;
use chillerlan\QRCode\QRCode;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Ações de transição de status de pedido (RF-26, RN-32), compartilhadas entre
 * o Kanban (App\Filament\Tenant\Pages\Kitchen) e o histórico
 * (Orders\Pages\ViewOrder) — uma única definição de cada ação, sem duplicar
 * autorização nem lógica de transição. Sempre recebem o pedido via
 * `arguments(['order' => $id])`, nunca via `->record()` — a mesma definição
 * de ação é reaproveitada pra vários cards diferentes no Kanban.
 */
class OrderStatusActions
{
    public static function advance(): Action
    {
        return Action::make('advance')
            ->label('Avançar')
            ->icon(Heroicon::OutlinedArrowRight)
            ->color('primary')
            ->authorize('manage_order_status')
            ->visible(function (array $arguments): bool {
                $order = self::resolveOrder($arguments);

                // Pedido Pronto + delivery com entregador é despachado via dispatch()
                // (exige escolher o entregador), não por este avanço genérico. Quando
                // o tenant não atribui entregador, o avanço genérico assume: leva a
                // "Em Transporte" ou direto a "Finalizado", conforme a config de etapa.
                if ($order->status === OrderStatus::Ready && $order->assignsDeliveryCourier()) {
                    return false;
                }

                return $order->status->canAdvanceGenerically();
            })
            ->action(function (array $arguments): void {
                try {
                    app(AdvanceOrderStatus::class)(self::resolveOrder($arguments), auth()->user());
                } catch (OrderTransitionException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Pedido atualizado')->success()->send();
            });
    }

    /**
     * Despacha um pedido Pronto de delivery: pede o entregador responsável e,
     * só então, avança para Em Transporte — variação de advance() para o
     * único caso em que a transição exige um dado extra (seção 5/16 da spec).
     */
    public static function dispatch(): Action
    {
        return Action::make('dispatch')
            ->label('Despachar')
            ->icon(Heroicon::OutlinedTruck)
            ->color('primary')
            ->authorize('manage_order_status')
            ->visible(function (array $arguments): bool {
                $order = self::resolveOrder($arguments);

                return $order->status === OrderStatus::Ready && $order->assignsDeliveryCourier();
            })
            ->schema([
                Select::make('assigned_delivery_user_id')
                    ->label('Entregador')
                    ->options(fn () => User::deliveryPersonnel()->orderBy('name')->pluck('name', 'id'))
                    ->required()
                    ->helperText('Cadastre um usuário com o papel Entregador antes de despachar.'),
            ])
            ->action(function (array $arguments, array $data): void {
                $order = self::resolveOrder($arguments);
                $actingUser = auth()->user();

                try {
                    DB::transaction(function () use ($order, $data, $actingUser): void {
                        $deliveryUser = User::query()
                            ->where('tenant_id', CurrentTenant::id())
                            ->findOrFail($data['assigned_delivery_user_id']);

                        app(AssignDeliveryUser::class)($order, $deliveryUser, $actingUser);
                        app(AdvanceOrderStatus::class)($order, $actingUser);
                    });
                } catch (OrderTransitionException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Pedido despachado')->success()->send();
            });
    }

    /**
     * Troca o entregador de um pedido sem alterar o status (ex.: entregador
     * ficou indisponível no meio da rota). Disponível na Central e no
     * histórico (ViewOrder).
     */
    public static function reassignDelivery(): Action
    {
        return Action::make('reassignDelivery')
            ->label('Trocar entregador')
            ->icon(Heroicon::OutlinedUserCircle)
            ->color('gray')
            ->authorize('manage_order_status')
            ->visible(function (array $arguments): bool {
                if (! (CurrentTenant::get()?->assigns_delivery_couriers ?? true)) {
                    return false;
                }

                $order = self::resolveOrder($arguments);

                return $order->assigned_delivery_user_id !== null
                    || in_array($order->status, [OrderStatus::Ready, OrderStatus::InTransit], true);
            })
            ->schema([
                Select::make('assigned_delivery_user_id')
                    ->label('Entregador')
                    ->options(fn () => User::deliveryPersonnel()->orderBy('name')->pluck('name', 'id'))
                    ->required(),
            ])
            ->action(function (array $arguments, array $data): void {
                $order = self::resolveOrder($arguments);

                try {
                    $deliveryUser = User::query()
                        ->where('tenant_id', CurrentTenant::id())
                        ->findOrFail($data['assigned_delivery_user_id']);
                    app(AssignDeliveryUser::class)($order, $deliveryUser, auth()->user());
                } catch (OrderTransitionException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Entregador atualizado')->success()->send();
            });
    }

    /**
     * Modal de detalhes completos do pedido (seção 13 da spec). Permissão
     * dupla: o Entregador (só mark_order_delivered) também precisa ver
     * endereço/observações do pedido que está entregando — "Visualizar
     * pedido" é ação disponível em todo status, inclusive Em Transporte.
     */
    public static function viewDetails(): Action
    {
        return Action::make('viewDetails')
            ->label('Ver detalhes')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->modalHeading(fn (array $arguments): string => 'Pedido #'.$arguments['order'])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalWidth('3xl')
            ->authorize(fn (): bool => (bool) auth()->user()?->can('manage_order_status') || (bool) auth()->user()?->can('mark_order_delivered'))
            ->modalContent(function (array $arguments) {
                $order = Order::query()
                    ->with(['client', 'deliveryOption', 'assignedDeliveryUser', 'cancelledBy', 'items.product', 'statusHistories.user'])
                    ->findOrFail($arguments['order']);

                return view('filament.orders.order-details-modal', ['order' => $order]);
            });
    }

    /**
     * Abre a comanda imprimível do pedido em nova aba (rota `order.ticket`,
     * protegida por auth + permissão no controller). Disponível em qualquer
     * status — reimprimir uma comanda é rotina de cozinha.
     */
    public static function printTicket(): Action
    {
        return Action::make('printTicket')
            ->label('Imprimir comanda')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->authorize('manage_order_status')
            ->url(fn (array $arguments): string => route('order.ticket', ['order' => $arguments['order']]))
            ->openUrlInNewTab();
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label('Cancelar')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->authorize('manage_order_status')
            ->visible(fn (array $arguments): bool => self::resolveOrder($arguments)->status->canBeCancelled())
            ->schema([
                Select::make('reason')
                    ->label('Motivo do cancelamento')
                    ->options(collect(CancellationReason::cases())->mapWithKeys(
                        fn (CancellationReason $reason): array => [$reason->value => $reason->label()]
                    ))
                    ->required(),
            ])
            ->action(function (array $arguments, array $data): void {
                try {
                    app(CancelOrder::class)(
                        self::resolveOrder($arguments),
                        CancellationReason::from($data['reason']),
                        auth()->user(),
                    );
                } catch (OrderTransitionException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Pedido cancelado')->success()->send();
            });
    }

    public static function markDelivered(): Action
    {
        return Action::make('markDelivered')
            ->label('Confirmar entrega')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            // Sem entregador nominal (config do tenant), quem avança o pedido na
            // Central também confirma a entrega — senão o Atendente levaria o pedido
            // até "Em Transporte" sem conseguir fechá-lo (só Gerente/Entregador têm
            // mark_order_delivered).
            ->authorize(fn (): bool => (bool) auth()->user()?->can('mark_order_delivered')
                || ((! (CurrentTenant::get()?->assigns_delivery_couriers ?? true)) && (bool) auth()->user()?->can('manage_order_status')))
            ->visible(fn (array $arguments): bool => self::resolveOrder($arguments)->status === OrderStatus::InTransit)
            ->action(function (array $arguments): void {
                try {
                    app(MarkOrderDelivered::class)(self::resolveOrder($arguments), auth()->user());
                } catch (OrderTransitionException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Entrega confirmada')->success()->send();
            });
    }

    public static function deliveryLink(): Action
    {
        return Action::make('deliveryLink')
            ->label('Link de entrega')
            ->icon(Heroicon::OutlinedQrCode)
            ->color('gray')
            ->modalHeading('Link de entrega')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->authorize('manage_order_status')
            ->visible(function (array $arguments): bool {
                $order = self::resolveOrder($arguments);

                return $order->usesInTransitStage()
                    && in_array($order->status, [OrderStatus::Ready, OrderStatus::InTransit], true);
            })
            ->modalContent(function (array $arguments) {
                $order = self::resolveOrder($arguments);

                $signedUrl = URL::temporarySignedRoute(
                    'delivery.confirmation',
                    now()->addHours(12),
                    ['order' => $order->id],
                );

                return view('filament.orders.delivery-link-modal', [
                    'url' => $signedUrl,
                    'qrDataUri' => (new QRCode)->render($signedUrl),
                    'whatsappUrl' => 'https://wa.me/?text='.rawurlencode("Link de entrega do pedido #{$order->id}: {$signedUrl}"),
                ]);
            });
    }

    private static function resolveOrder(array $arguments): Order
    {
        return Order::query()->findOrFail($arguments['order']);
    }
}
