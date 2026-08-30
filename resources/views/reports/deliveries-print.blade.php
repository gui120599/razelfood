@php
    $money = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $minutes = fn ($m) => $m === null ? '—' : \App\Support\Orders\DurationFormatter::minutes($m);
@endphp

<x-reports.print-layout title="Relatório de Entregas por Entregador" :tenant="$tenant" :period-label="$periodLabel">
    @if ($groups->isEmpty())
        <p>Nenhuma entrega registrada no período.</p>
    @else
        @foreach ($groups as $group)
            <div class="group">
                <p class="group-title">{{ $group['name'] }}</p>
                <p class="group-summary muted">
                    {{ $group['count'] }} entrega(s) ·
                    Total {{ $money($group['total']) }} ·
                    Taxas de entrega {{ $money($group['delivery_fee_total']) }} ·
                    Tempo médio {{ $minutes($group['avg_minutes']) }}
                </p>

                <table>
                    <thead>
                        <tr>
                            <th>Nº</th>
                            <th>Entregue em</th>
                            <th>Cliente</th>
                            <th>Bairro</th>
                            <th>Endereço</th>
                            <th>Pagamento</th>
                            <th class="num">Taxa</th>
                            <th class="num">Total</th>
                            <th class="num">Tempo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group['orders'] as $order)
                            <tr>
                                <td>{{ $order['number'] }}</td>
                                <td>{{ $order['delivered_at']?->format('d/m/Y H:i') }}</td>
                                <td>{{ $order['client'] ?? '—' }}</td>
                                <td>{{ $order['neighborhood'] ?? '—' }}</td>
                                <td>{{ $order['address'] ?? '—' }}</td>
                                <td>{{ $order['payments'] ?: '—' }}</td>
                                <td class="num">{{ $money($order['delivery_fee']) }}</td>
                                <td class="num">{{ $money($order['total']) }}</td>
                                <td class="num">{{ $minutes($order['minutes']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @endif
</x-reports.print-layout>
