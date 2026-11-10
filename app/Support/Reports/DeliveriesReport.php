<?php

namespace App\Support\Reports;

use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Agrupa os pedidos entregues no período por entregador
 * (`assigned_delivery_user_id`). Fonte única do relatório de entregas —
 * usada pelo widget, pela exportação CSV e pela versão imprimível.
 * Escopo de tenant automático via TenantScope de Order.
 */
class DeliveriesReport
{
    /**
     * Entregas por dia de calendário, do início do primeiro dia ao fim do último.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function groups(CarbonInterface $start, CarbonInterface $end): Collection
    {
        return $this->build(Order::query()->deliveredBetween($start, $end));
    }

    /**
     * Entregas numa janela exata [início, fim) — o turno, que pode atravessar a meia-noite.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function groupsWithin(CarbonInterface $from, CarbonInterface $until): Collection
    {
        return $this->build(Order::query()->deliveredWithin($from, $until));
    }

    /**
     * @return Collection<int, array{
     *     user_id: ?int,
     *     name: string,
     *     count: int,
     *     total: float,
     *     delivery_fee_total: float,
     *     avg_minutes: ?int,
     *     orders: array<int, array{number: int|string, delivered_at: ?CarbonInterface, client: ?string, address: ?string, neighborhood: ?string, total: float, delivery_fee: float, payments: string, minutes: ?int}>
     * }>
     */
    private function build(Builder $query): Collection
    {
        $orders = $query
            ->whereNotNull('delivered_at')
            ->whereNotNull('assigned_delivery_user_id')
            ->with(['assignedDeliveryUser:id,name', 'client:id,name', 'payments'])
            ->orderBy('delivered_at')
            ->get();

        return $orders
            ->groupBy('assigned_delivery_user_id')
            ->map(function (Collection $group): array {
                $durations = $group
                    ->filter(fn (Order $order): bool => $order->in_transit_at !== null && $order->delivered_at !== null)
                    ->map(fn (Order $order): int => $order->in_transit_at->diffInMinutes($order->delivered_at));

                return [
                    'user_id' => $group->first()->assigned_delivery_user_id,
                    'name' => $group->first()->assignedDeliveryUser?->name ?? 'Entregador removido',
                    'count' => $group->count(),
                    'total' => (float) $group->sum('grand_total'),
                    'delivery_fee_total' => (float) $group->sum('delivery_fee'),
                    'avg_minutes' => $durations->isNotEmpty() ? (int) round($durations->avg()) : null,
                    'orders' => $group->map(fn (Order $order): array => [
                        'number' => $order->order_number ?? $order->id,
                        'delivered_at' => $order->delivered_at,
                        'client' => $order->client?->name,
                        'address' => $order->delivery_address,
                        'neighborhood' => $order->delivery_neighborhood,
                        'total' => (float) $order->grand_total,
                        'delivery_fee' => (float) $order->delivery_fee,
                        'payments' => $order->payments->pluck('payment_option_name')->implode(' + '),
                        'minutes' => ($order->in_transit_at !== null && $order->delivered_at !== null)
                            ? $order->in_transit_at->diffInMinutes($order->delivered_at)
                            : null,
                    ])->values()->all(),
                ];
            })
            ->sortByDesc('count')
            ->values();
    }
}
