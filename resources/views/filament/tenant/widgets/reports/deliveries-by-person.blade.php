@php
    $groups = $this->deliveryGroups();
    $money = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $minutes = fn ($m) => $m === null ? '—' : \App\Support\Orders\DurationFormatter::minutes($m);
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="Entregas por entregador">
        @if ($groups->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Nenhuma entrega registrada no período selecionado.</p>
        @else
            <div class="space-y-6">
                @foreach ($groups as $group)
                    <div>
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-gray-200 pb-1.5 dark:border-white/10">
                            <span class="font-semibold text-gray-900 dark:text-white">{{ $group['name'] }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $group['count'] }} entrega(s) ·
                                Total {{ $money($group['total']) }} ·
                                Taxas {{ $money($group['delivery_fee_total']) }} ·
                                Tempo médio {{ $minutes($group['avg_minutes']) }}
                            </span>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="mt-2 w-full text-sm">
                                <thead>
                                    <tr class="text-left text-xs font-semibold uppercase text-gray-400">
                                        <th class="py-1 pr-3">Nº</th>
                                        <th class="py-1 pr-3">Entregue</th>
                                        <th class="py-1 pr-3">Cliente</th>
                                        <th class="py-1 pr-3">Bairro</th>
                                        <th class="py-1 pr-3">Pagamento</th>
                                        <th class="py-1 pr-3 text-right">Taxa</th>
                                        <th class="py-1 pr-3 text-right">Total</th>
                                        <th class="py-1 text-right">Tempo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                    @foreach ($group['orders'] as $order)
                                        <tr class="text-gray-700 dark:text-gray-200">
                                            <td class="py-1 pr-3">{{ $order['number'] }}</td>
                                            <td class="py-1 pr-3 tabular-nums">{{ $order['delivered_at']?->format('d/m H:i') }}</td>
                                            <td class="py-1 pr-3">{{ $order['client'] ?? '—' }}</td>
                                            <td class="py-1 pr-3">{{ $order['neighborhood'] ?? '—' }}</td>
                                            <td class="py-1 pr-3">{{ $order['payments'] ?: '—' }}</td>
                                            <td class="py-1 pr-3 text-right tabular-nums">{{ $money($order['delivery_fee']) }}</td>
                                            <td class="py-1 pr-3 text-right tabular-nums">{{ $money($order['total']) }}</td>
                                            <td class="py-1 text-right tabular-nums">{{ $minutes($order['minutes']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
