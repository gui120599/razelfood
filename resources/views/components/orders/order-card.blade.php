@props([
    'order',
    'urgency',
    'minutesInStage',
    'primaryAction',
    'primaryLabel',
    'cancelLabel',
])

@php
    $urgencyAccent = match ($urgency->value) {
        'late' => 'border-l-red-500',
        'attention' => 'border-l-amber-400',
        default => 'border-l-transparent',
    };

    $fulfillment = $order->fulfillmentType();
    $visibleItems = $order->items->take(2);
    $remainingItemsCount = max(0, $order->items->count() - $visibleItems->count());
@endphp

<div
    wire:key="order-card-{{ $order->id }}"
    {{ $attributes->class(["rounded-lg border border-l-4 border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900 {$urgencyAccent}"]) }}
>
    <div
        wire:click="mountAction('viewDetails', { order: {{ $order->id }} })"
        class="cursor-pointer space-y-1.5 p-3"
    >
        <div class="flex items-start justify-between gap-2">
            <span class="text-base font-bold text-gray-900 dark:text-white">#{{ $order->id }}</span>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $order->opened_at?->format('H:i') }}</span>
        </div>

        <p class="truncate text-sm text-gray-700 dark:text-gray-200">{{ $order->client?->name ?? 'Cliente' }}</p>

        <span class="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-gray-600 dark:bg-white/10 dark:text-gray-300">
            {{ $fulfillment->label() }}
        </span>

        @if ($visibleItems->isNotEmpty())
            <div class="text-xs text-gray-500 dark:text-gray-400">
                @foreach ($visibleItems as $item)
                    <div class="flex items-center gap-1">
                        <p class="truncate">{{ $item->quantity }}x {{ $item->displayName }}</p>
                        @if ($item->product?->category?->name)
                            <span class="shrink-0 rounded bg-gray-100 px-1 text-[9px] font-semibold uppercase text-gray-500 dark:bg-white/10 dark:text-gray-400">
                                {{ $item->product->category->name }}
                            </span>
                        @endif
                    </div>
                    @foreach ($item->addonsDisplay ?? [] as $addonLine)
                        <p class="truncate pl-3 text-gray-400">+ {{ $addonLine }}</p>
                    @endforeach
                @endforeach
                @if ($remainingItemsCount > 0)
                    <p class="font-medium text-gray-400">+ {{ $remainingItemsCount }} {{ $remainingItemsCount === 1 ? 'item' : 'itens' }}</p>
                @endif
            </div>
        @endif

        <hr class="border-gray-100 dark:border-white/5">

        <div class="flex items-center justify-between gap-2">
            <span class="text-sm font-semibold text-gray-900 dark:text-white">
                R$ {{ number_format($order->grand_total, 2, ',', '.') }}
            </span>

            @if ($order->assignedDeliveryUser)
                <span class="flex items-center gap-1 truncate text-xs text-gray-500 dark:text-gray-400">
                    <x-heroicon-o-truck class="h-3.5 w-3.5 shrink-0" />
                    {{ $order->assignedDeliveryUser->name }}
                </span>
            @endif
        </div>

        @if ($urgency->value === 'late')
            <p class="rounded bg-red-50 px-2 py-1 text-xs font-bold text-red-600 dark:bg-red-500/10 dark:text-red-400">
                🔴 ATRASADO
                @if ($minutesInStage !== null)
                    · {{ \App\Support\Orders\DurationFormatter::minutes($minutesInStage) }}
                @endif
            </p>
        @elseif ($minutesInStage !== null)
            <p @class([
                'text-xs',
                'font-semibold text-amber-600 dark:text-amber-400' => $urgency->value === 'attention',
                'text-gray-400' => $urgency->value === 'normal',
            ])>
                ⏱ {{ \App\Support\Orders\DurationFormatter::minutes($minutesInStage) }}
            </p>
        @endif
    </div>

    <div class="flex items-center gap-1.5 border-t border-gray-100 p-2 dark:border-white/5">
        @if ($primaryAction === 'markDelivered')
            @can('mark_order_delivered')
                <button
                    type="button"
                    wire:click="mountAction('markDelivered', { order: {{ $order->id }} })"
                    class="fi-btn fi-btn-color-success flex-1 rounded-lg bg-green-600 px-2.5 py-2 text-xs font-semibold text-white"
                >
                    {{ $primaryLabel }}
                </button>
            @endcan
        @elseif ($primaryAction !== 'viewDetails')
            @can('manage_order_status')
                <button
                    type="button"
                    wire:click="mountAction('{{ $primaryAction }}', { order: {{ $order->id }} })"
                    class="fi-btn fi-btn-color-primary flex-1 rounded-lg bg-amber-500 px-2.5 py-2 text-xs font-semibold text-white"
                >
                    {{ $primaryLabel }}
                </button>
            @endcan
        @endif

        @if (auth()->user()?->can('manage_order_status') || auth()->user()?->can('mark_order_delivered'))
            {{--
                Não uso <x-filament-actions::group> aqui de propósito: o Filament 4.12
                não propaga ->arguments() pro wire:click gerado dentro de um grupo/dropdown
                (cada item resolve a Action de novo no servidor, sem os argumentos que
                setei no Blade). O padrão abaixo — mountAction com os argumentos embutidos
                no próprio clique — é o mesmo que os botões principais já usam.
            --}}
            <div x-data="{ open: false }" class="relative">
                <button
                    type="button"
                    x-on:click="open = !open"
                    x-on:click.outside="open = false"
                    class="fi-btn fi-btn-color-gray rounded-lg border border-gray-300 p-2 text-gray-500 dark:border-gray-600 dark:text-gray-400"
                >
                    <x-heroicon-o-ellipsis-vertical class="h-4 w-4" />
                </button>

                <div
                    x-show="open"
                    x-cloak
                    x-transition
                    class="absolute right-0 bottom-full z-10 mb-1 w-40 rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-white/10 dark:bg-gray-800"
                >
                    <button
                        type="button"
                        wire:click="mountAction('viewDetails', { order: {{ $order->id }} })"
                        x-on:click="open = false"
                        class="block w-full px-3 py-2 text-left text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                    >
                        Ver detalhes
                    </button>

                    @can('manage_order_status')
                        <a
                            href="{{ route('order.ticket', ['order' => $order->id]) }}"
                            target="_blank"
                            x-on:click="open = false"
                            class="block w-full px-3 py-2 text-left text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                        >
                            Imprimir comanda
                        </a>
                    @endcan

                    @can('manage_order_status')
                        @if ($order->status->canBeCancelled())
                            <button
                                type="button"
                                wire:click="mountAction('cancel', { order: {{ $order->id }} })"
                                x-on:click="open = false"
                                class="block w-full px-3 py-2 text-left text-xs text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10"
                            >
                                {{ $cancelLabel }}
                            </button>
                        @endif
                    @endcan
                </div>
            </div>
        @endif
    </div>
</div>
