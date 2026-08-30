@php
    $money = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $billable = $orders->where('status', '!=', \App\Enums\OrderStatus::Cancelled);
@endphp

<x-reports.print-layout title="Relatório de Pedidos" :tenant="$tenant" :period-label="$periodLabel">
    @if ($orders->isEmpty())
        <p>Nenhum pedido no período.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Nº</th>
                    <th>Data/Hora</th>
                    <th>Cliente</th>
                    <th>Modalidade</th>
                    <th>Bairro</th>
                    <th>Pagamento</th>
                    <th>Status</th>
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($orders as $order)
                    <tr>
                        <td>{{ $order->order_number ?? $order->id }}</td>
                        <td>{{ $order->opened_at?->format('d/m/Y H:i') }}</td>
                        <td>{{ $order->client?->name ?? '—' }}</td>
                        <td>{{ $order->deliveryOption?->name ?? 'Retirada no local' }}</td>
                        <td>{{ $order->delivery_neighborhood ?? '—' }}</td>
                        <td>{{ $order->payments->pluck('payment_option_name')->implode(' + ') ?: '—' }}</td>
                        <td>{{ $order->status->label() }}</td>
                        <td class="num">{{ $money($order->grand_total) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="7">
                        {{ $orders->count() }} pedido(s) ·
                        {{ $billable->count() }} não cancelado(s)
                    </td>
                    <td class="num">{{ $money($billable->sum('grand_total')) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif
</x-reports.print-layout>
