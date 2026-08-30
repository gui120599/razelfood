<x-layouts.public :tenant="$tenant">
    <div class="space-y-4 pb-10">
        <div class="flex items-center gap-2">
            <a href="{{ route('menu.index') }}" class="text-gray-400 transition hover:text-white">
                <x-heroicon-o-chevron-left class="h-6 w-6" />
            </a>
            <h1 class="text-lg font-bold text-white">Acompanhar pedido</h1>
        </div>

        <livewire:order-status-timeline :order="$order" />

        {{-- Itens --}}
        <div class="rounded-xl border border-white/10 bg-gray-900 p-4">
            <h2 class="mb-2 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-gray-400">
                <x-heroicon-o-shopping-cart class="h-4 w-4" />
                Itens
            </h2>
            <div class="divide-y divide-white/10">
                @foreach ($itemLines as $line)
                    <div class="flex items-start justify-between gap-2 py-2 text-sm">
                        <div class="min-w-0">
                            <p class="text-gray-200">
                                {{ $line['quantity'] }}x
                                @if ($line['category_name'])
                                    <span class="mx-1 rounded-full bg-white/10 px-1.5 py-0.5 text-[10px] font-bold uppercase text-gray-400">{{ $line['category_name'] }}</span>
                                @endif
                                {{ $line['name'] }}
                            </p>
                            @foreach ($line['addons_display'] as $addonLine)
                                <p class="text-xs text-gray-500">+ {{ $addonLine }}</p>
                            @endforeach
                            @if ($line['note'])
                                <p class="text-xs italic text-gray-500">"{{ $line['note'] }}"</p>
                            @endif
                        </div>
                        <span class="shrink-0 font-medium text-gray-200">R$ {{ number_format($line['line_total'], 2, ',', '.') }}</span>
                    </div>
                @endforeach
            </div>

            <dl class="mt-3 space-y-1 border-t border-white/10 pt-3 text-sm">
                <div class="flex justify-between text-gray-400">
                    <dt>Subtotal</dt>
                    <dd>R$ {{ number_format($order->items_total, 2, ',', '.') }}</dd>
                </div>
                @if ($order->discount_total > 0)
                    <div class="flex justify-between text-green-400">
                        <dt>Desconto</dt>
                        <dd>-R$ {{ number_format($order->discount_total, 2, ',', '.') }}</dd>
                    </div>
                @endif
                <div class="flex justify-between text-gray-400">
                    <dt>Entrega</dt>
                    <dd>R$ {{ number_format($order->delivery_fee, 2, ',', '.') }}</dd>
                </div>
                <div class="flex justify-between text-base font-bold text-white">
                    <dt>Total</dt>
                    <dd class="text-green-400">R$ {{ number_format($order->grand_total, 2, ',', '.') }}</dd>
                </div>
            </dl>
        </div>

        {{-- Entrega e pagamento --}}
        <div class="space-y-2.5 rounded-xl border border-white/10 bg-gray-900 p-4">
            <h2 class="mb-1 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-gray-400">
                <x-heroicon-o-truck class="h-4 w-4" />
                Entrega e pagamento
            </h2>

            <div class="flex items-start gap-2 text-sm">
                <x-heroicon-o-truck class="mt-0.5 h-4 w-4 shrink-0 text-gray-500" />
                <p class="text-gray-300">{{ $order->deliveryOption?->name ?? 'Retirada no local' }}</p>
            </div>

            @if ($order->delivery_address)
                <div class="flex items-start gap-2 text-sm">
                    <x-heroicon-o-map-pin class="mt-0.5 h-4 w-4 shrink-0 text-gray-500" />
                    <p class="text-gray-300">{{ $order->delivery_address }}</p>
                </div>
            @endif

            <div class="flex items-start gap-2 text-sm">
                <x-heroicon-o-credit-card class="mt-0.5 h-4 w-4 shrink-0 text-gray-500" />
                <div class="text-gray-300">
                    @foreach ($order->payments as $payment)
                        <p>
                            {{ $payment->payment_option_name }}
                            @if ($order->payments->count() > 1)
                                <span class="text-gray-500">— R$ {{ number_format($payment->amount, 2, ',', '.') }}</span>
                            @endif
                            @if ($payment->change_for)
                                <span class="text-gray-500">(troco para R$ {{ number_format($payment->change_for, 2, ',', '.') }})</span>
                            @endif
                        </p>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Observação do pedido --}}
        @if ($order->notes)
            <div class="rounded-xl border border-white/10 bg-gray-900 p-4">
                <h2 class="mb-1 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-gray-400">
                    <x-heroicon-o-chat-bubble-left-ellipsis class="h-4 w-4" />
                    Observação
                </h2>
                <p class="text-sm text-gray-300">{{ $order->notes }}</p>
            </div>
        @endif
    </div>
</x-layouts.public>
