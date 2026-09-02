@php
    $order->loadMissing(['client', 'deliveryOption', 'assignedDeliveryUser', 'cancelledBy', 'items.product.category', 'statusHistories.user']);
@endphp

<div class="space-y-5 text-sm">
    {{-- Cabeçalho --}}
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-3 dark:border-white/10">
        <div>
            <p class="text-lg font-bold text-gray-900 dark:text-white">Pedido #{{ $order->id }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $order->opened_at?->format('d/m/Y H:i') }} · {{ $order->fulfillmentType()->label() }}</p>
        </div>
        <span
            @class([
                'rounded-full px-3 py-1 text-xs font-bold uppercase',
                'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300' => $order->status->color() === 'gray',
                'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400' => $order->status->color() === 'info',
                'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' => $order->status->color() === 'warning',
                'bg-green-100 text-green-700 dark:bg-green-500/10 dark:text-green-400' => $order->status->color() === 'success',
                'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-400' => $order->status->color() === 'danger',
            ])
        >
            {{ $order->status->label() }}
        </span>
    </div>

    {{-- Cliente --}}
    <div>
        <p class="mb-1 text-xs font-bold uppercase text-gray-400">Cliente</p>
        <p class="text-gray-900 dark:text-white">{{ $order->client?->name ?? '—' }}</p>
        <p class="text-gray-500 dark:text-gray-400">{{ $order->client?->phone ?? '—' }}</p>
    </div>

    {{-- Entrega --}}
    @if ($order->delivery_option_id !== null)
        <div>
            <p class="mb-1 text-xs font-bold uppercase text-gray-400">Entrega</p>
            <p class="text-gray-900 dark:text-white">{{ $order->deliveryOption?->name }}</p>
            <p class="text-gray-500 dark:text-gray-400">{{ $order->delivery_address ?? '—' }}</p>
            @if ($order->assignedDeliveryUser)
                <p class="mt-1 text-gray-500 dark:text-gray-400">Entregador: {{ $order->assignedDeliveryUser->name }}</p>
            @endif
        </div>
    @endif

    {{-- Itens --}}
    <div>
        <p class="mb-1 text-xs font-bold uppercase text-gray-400">Itens</p>
        <div class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($order->items as $item)
                <div class="flex items-start justify-between gap-3 py-1.5">
                    <div>
                        <div class="flex items-center gap-1.5">
                            <p class="text-gray-900 dark:text-white">
                                {{ $item->quantity }}x
                                {{ $item->flavors
                                    ? \App\Models\Product::withTrashed()->whereIn('id', $item->flavors)->pluck('name')->implode(' / ')
                                    : ($item->product?->name ?? 'Produto removido') }}
                            </p>
                            @if ($item->product?->category?->name)
                                <span class="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-500 dark:bg-white/10 dark:text-gray-400">
                                    {{ $item->product->category->name }}
                                </span>
                            @endif
                        </div>
                        @foreach ($item->gifts ?? [] as $gift)
                            @php($giftName = \App\Models\Product::withTrashed()->find($gift['gift_product_id'])?->name ?? 'Brinde removido')
                            <p class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                {{ ($gift['accepted'] ?? false) === true
                                    ? "🎁 {$gift['quantity']}x {$giftName}"
                                    : "🎁 {$giftName} — recusado pelo cliente" }}
                            </p>
                        @endforeach
                        @if ($item->note)
                            <p class="text-xs italic text-gray-500 dark:text-gray-400">Obs: {{ $item->note }}</p>
                        @endif
                    </div>
                    <span class="shrink-0 text-gray-700 dark:text-gray-300">R$ {{ number_format($item->unit_price, 2, ',', '.') }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Financeiro --}}
    <div>
        <p class="mb-1 text-xs font-bold uppercase text-gray-400">Valores</p>
        <div class="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
            <div>
                <p class="text-xs text-gray-400">Subtotal</p>
                <p class="text-gray-900 dark:text-white">R$ {{ number_format($order->items_total, 2, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Desconto</p>
                <p class="text-gray-900 dark:text-white">R$ {{ number_format($order->discount_total, 2, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Entrega</p>
                <p class="text-gray-900 dark:text-white">R$ {{ number_format($order->delivery_fee, 2, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Total</p>
                <p class="font-bold text-gray-900 dark:text-white">R$ {{ number_format($order->grand_total, 2, ',', '.') }}</p>
            </div>
        </div>
        <div class="mt-2 text-gray-500 dark:text-gray-400">
            @forelse ($order->payments as $payment)
                <p>
                    Pagamento: {{ $payment->payment_option_name }}
                    @if ($order->payments->count() > 1)
                        (R$ {{ number_format($payment->amount, 2, ',', '.') }})
                    @endif
                    @if ($payment->change_for)
                        · Troco para R$ {{ number_format($payment->change_for, 2, ',', '.') }}
                    @endif
                </p>
            @empty
                <p>Pagamento: —</p>
            @endforelse
        </div>
    </div>

    {{-- Cancelamento --}}
    @if ($order->status === \App\Enums\OrderStatus::Cancelled)
        <div class="rounded-lg bg-red-50 p-3 dark:bg-red-500/5">
            <p class="mb-1 text-xs font-bold uppercase text-red-600 dark:text-red-400">Cancelamento</p>
            <p class="text-gray-900 dark:text-white">{{ $order->cancellation_reason?->label() }}</p>
            <p class="text-gray-500 dark:text-gray-400">Por: {{ $order->cancelledBy?->name ?? 'Cliente / não identificado' }}</p>
        </div>
    @endif

    {{-- Timeline --}}
    <div>
        <p class="mb-2 text-xs font-bold uppercase text-gray-400">Linha do tempo</p>
        @php($history = $order->statusHistories->sortBy('created_at'))
        @if ($history->isEmpty())
            <p class="text-xs text-gray-400">Sem histórico de transições registrado.</p>
        @else
            <ol class="relative ml-2 border-l-2 border-gray-200 dark:border-white/10">
                @foreach ($history as $entry)
                    <li class="relative mb-4 ml-4 last:mb-0">
                        <span class="absolute -left-[21px] h-2.5 w-2.5 rounded-full border-2 border-white bg-amber-500 dark:border-gray-900"></span>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $entry->status_to->label() }}</p>
                        <p class="text-xs text-gray-400">{{ $entry->created_at?->format('d/m H:i') }} — {{ $entry->user?->name ?? 'Sistema / cliente' }}</p>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</div>
