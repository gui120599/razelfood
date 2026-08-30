@php
    $quickFilters = [
        'all' => 'Todos',
        'delivery' => 'Delivery',
        'pickup' => 'Retirada',
        'dine_in' => 'Consumo local',
        'pending' => 'Pendentes',
        'preparing' => 'Em preparo',
        'ready' => 'Prontos',
        'in_transit' => 'Em entrega',
        'finished' => 'Finalizados',
    ];

    $columns = $this->boardColumns();
    $finishedOrders = $this->ordersByStatus->get(\App\Enums\OrderStatus::Delivered->value, collect())
        ->concat($this->ordersByStatus->get(\App\Enums\OrderStatus::Finished->value, collect()))
        ->sortByDesc('opened_at')
        ->values();
@endphp

<x-filament-panels::page>
    <div class="space-y-3">
        {{-- Barra de filtros — uma linha só, compacta --}}
        <div class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-2.5 dark:border-white/10 dark:bg-gray-900">
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Buscar pedido, cliente ou telefone..."
                class="fi-input min-w-[180px] flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
            >

            <select wire:model.live="quickFilter" class="fi-select rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                @foreach ($quickFilters as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>

            @if ($this->showsDeliveryPersonnelFilter())
                <select wire:model.live="deliveryUserFilter" class="fi-select rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                    <option value="">Todos entregadores</option>
                    @foreach ($this->deliveryPersonnelOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            @endif

            <select wire:model.live="productionLineFilter" class="fi-select rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                <option value="">Todas as linhas</option>
                @foreach ($this->productionLines as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>

            <div class="flex items-center gap-1">
                <input type="date" wire:model.live="periodFrom" class="fi-input w-32 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                <span class="text-xs text-gray-400">até</span>
                <input type="date" wire:model.live="periodUntil" class="fi-input w-32 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
            </div>

            <button
                type="button"
                wire:click="$toggle('onlyLate')"
                @class([
                    'shrink-0 rounded-lg border px-3 py-2 text-xs font-semibold',
                    'border-red-300 bg-red-50 text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-400' => $onlyLate,
                    'border-gray-300 text-gray-600 dark:border-gray-600 dark:text-gray-300' => ! $onlyLate,
                ])
            >
                ⚠ Atrasados
            </button>

            <button
                type="button"
                wire:click="$toggle('showCancelled')"
                @class([
                    'shrink-0 rounded-lg border px-3 py-2 text-xs font-semibold',
                    'border-gray-400 bg-gray-100 text-gray-700 dark:border-gray-500 dark:bg-white/10 dark:text-gray-200' => $showCancelled,
                    'border-gray-300 text-gray-600 dark:border-gray-600 dark:text-gray-300' => ! $showCancelled,
                ])
            >
                {{ $showCancelled ? 'Ocultar cancelados' : 'Ver cancelados' }}
            </button>

            <span class="ml-auto shrink-0 text-xs text-gray-400" wire:poll.20s="refreshBoard">
                🔄 Atualizado às {{ $lastRefreshedAt }}
            </span>
        </div>

        {{-- Board Kanban --}}
        <div x-data="{ activeColumn: 'started' }">
            <div class="flex gap-2 overflow-x-auto pb-2 md:hidden">
                @foreach ($columns as $status)
                    <button
                        type="button"
                        x-on:click="activeColumn = '{{ $status->value }}'"
                        :class="activeColumn === '{{ $status->value }}' ? 'bg-amber-500 text-white' : 'bg-gray-100 text-gray-600'"
                        class="shrink-0 rounded-full px-3 py-1.5 text-xs font-semibold dark:bg-white/10 dark:text-gray-300"
                    >
                        {{ $this->columnLabel($status) }}
                    </button>
                @endforeach
                <button
                    type="button"
                    x-on:click="activeColumn = 'finished'"
                    :class="activeColumn === 'finished' ? 'bg-amber-500 text-white' : 'bg-gray-100 text-gray-600'"
                    class="shrink-0 rounded-full px-3 py-1.5 text-xs font-semibold dark:bg-white/10 dark:text-gray-300"
                >
                    Finalizados
                </button>
            </div>

            <div class="flex gap-4 overflow-x-auto pb-4">
                @foreach ($columns as $status)
                    @php($orders = $this->ordersByStatus->get($status->value, collect()))

                    <div
                        :class="activeColumn === '{{ $status->value }}' ? 'flex' : 'hidden'"
                        class="w-80 shrink-0 flex-col rounded-xl border border-gray-200 bg-gray-100 p-2.5 dark:border-transparent dark:bg-white/5 md:flex"
                    >
                        <div class="mb-2 flex shrink-0 items-center gap-1.5 px-1">
                            <span class="h-2 w-2 shrink-0 rounded-full {{ $this->columnDotClass($status) }}"></span>
                            <span class="text-sm font-bold text-gray-700 dark:text-gray-200">
                                {{ $this->columnLabel($status) }} · {{ $orders->count() }}
                            </span>
                        </div>

                        {{-- Altura aproximada; ajustar depois de ver o resultado real no navegador. --}}
                        <div class="flex-1 space-y-3 overflow-y-auto pr-1" style="max-height: calc(100vh - 320px)">
                            @forelse ($orders as $order)
                                <x-orders.order-card
                                    :order="$order"
                                    :urgency="$this->urgencyFor($order)"
                                    :minutes-in-stage="$this->minutesInStageFor($order)"
                                    :primary-action="$this->primaryActionName($order)"
                                    :primary-label="$this->primaryActionLabel($order)"
                                    :cancel-label="$this->cancelLabel($order)"
                                />
                            @empty
                                <p class="px-1 text-xs text-gray-400">Nenhum pedido.</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach

                <div
                    :class="activeColumn === 'finished' ? 'flex' : 'hidden'"
                    class="w-80 shrink-0 flex-col rounded-xl bg-gray-50 p-2.5 dark:bg-white/5 md:flex"
                >
                    <div class="mb-2 flex shrink-0 items-center gap-1.5 px-1">
                        <span class="h-2 w-2 shrink-0 rounded-full bg-emerald-500"></span>
                        <span class="text-sm font-bold text-gray-700 dark:text-gray-200">
                            Finalizados · {{ $finishedOrders->count() }}
                        </span>
                    </div>

                    <div class="flex-1 space-y-3 overflow-y-auto pr-1" style="max-height: calc(100vh - 320px)">
                        @forelse ($finishedOrders as $order)
                            <x-orders.order-card
                                :order="$order"
                                :urgency="$this->urgencyFor($order)"
                                :minutes-in-stage="$this->minutesInStageFor($order)"
                                :primary-action="$this->primaryActionName($order)"
                                :primary-label="$this->primaryActionLabel($order)"
                                :cancel-label="$this->cancelLabel($order)"
                            />
                        @empty
                            <p class="px-1 text-xs text-gray-400">Nenhum pedido finalizado neste turno.</p>
                        @endforelse

                        <a
                            href="{{ \App\Filament\Tenant\Resources\Orders\OrderResource::getUrl('index') }}"
                            class="block px-1 py-1 text-xs font-semibold text-amber-600 hover:underline dark:text-amber-400"
                        >
                            Ver histórico completo →
                        </a>
                    </div>
                </div>
            </div>
        </div>

        {{-- Cancelados --}}
        @if ($showCancelled)
            <div class="rounded-xl border border-red-200 bg-red-50/50 p-3 dark:border-red-500/20 dark:bg-red-500/5">
                <div class="mb-3 flex items-center justify-between px-1">
                    <span class="text-sm font-bold text-red-700 dark:text-red-400">Cancelados neste turno</span>
                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-500/10 dark:text-red-400">
                        {{ $this->cancelledOrders->count() }}
                    </span>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @forelse ($this->cancelledOrders as $order)
                        <x-orders.order-card
                            :order="$order"
                            :urgency="$this->urgencyFor($order)"
                            :minutes-in-stage="$this->minutesInStageFor($order)"
                            :primary-action="$this->primaryActionName($order)"
                            :primary-label="$this->primaryActionLabel($order)"
                            :cancel-label="$this->cancelLabel($order)"
                        />
                    @empty
                        <p class="px-1 text-xs text-gray-400">Nenhum pedido cancelado neste turno.</p>
                    @endforelse
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
