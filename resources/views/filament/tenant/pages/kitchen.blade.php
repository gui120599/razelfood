@php
    // Rótulos iguais aos do Painel de Pedidos original; as chaves (valores) são o contrato de Kitchen::quickFilter.
    $quickFilters = [
        'all' => 'Todos',
        'delivery' => 'Delivery',
        'pickup' => 'Retirada',
        'dine_in' => 'Mesa',
        'pending' => 'Novos',
        'preparing' => 'Preparando',
        'ready' => 'Prontos',
        'in_transit' => 'Em entrega',
        'finished' => 'Finalizados',
    ];

    // Colunas visíveis: as do fluxo + a última ("Finalizados": Entregue e Finalizado juntos, marcada por
    // Delivered), menos as que o usuário escondeu.
    $columns = $this->visibleColumns();
    $finishedOrders = $this->ordersByStatus->get(\App\Enums\OrderStatus::Delivered->value, collect())
        ->concat($this->ordersByStatus->get(\App\Enums\OrderStatus::Finished->value, collect()))
        ->sortByDesc('id')
        ->values();
    $canOpenAttendance = $this->canOpenAttendance();
@endphp

<x-filament-panels::page>
    <div
        x-data="{
            irPara(id) {
                document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
            },
        }"
    >
        {{-- Barra de filtros — uma linha só, compacta --}}
        <div class="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-2.5 dark:border-white/10 dark:bg-gray-900">
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Buscar pedido, cliente ou celular..."
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

            @if ($this->productionLines->isNotEmpty())
                <select wire:model.live="productionLineFilter" class="fi-select rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                    <option value="">Todas as linhas</option>
                    @foreach ($this->productionLines as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            @endif

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
                    'border-danger-300 bg-danger-50 text-danger-700 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-400' => $onlyLate,
                    'border-gray-300 text-gray-600 dark:border-gray-600 dark:text-gray-300' => ! $onlyLate,
                ])
            >
                ⚠ Só atrasados
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

        {{-- Barra superior: navegação entre colunas, pedidos de turnos anteriores e colunas visíveis --}}
        <div class="mb-3 flex flex-wrap items-center gap-2">
            {{-- Chips que rolam até a coluna: navegação rápida no tablet sem precisar de abas (que exigiriam
                 condicionar a árvore inteira). Somem a partir de xl, onde as colunas viram grid. --}}
            <div class="flex flex-1 flex-wrap items-center gap-1.5 xl:hidden">
                @foreach ($columns as $column)
                    <button
                        type="button"
                        @click="irPara('coluna-{{ Str::slug($column->value) }}')"
                        class="rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10"
                    >
                        {{ $this->chipLabel($column) }}
                    </button>
                @endforeach
            </div>

            @if ($this->outOfShiftTotal > 0)
                <button
                    type="button"
                    wire:click="toggleOutOfShift"
                    @class([
                        'flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                        'bg-amber-100 text-amber-800 hover:bg-amber-200 dark:bg-amber-500/15 dark:text-amber-400' => ! $showOutOfShift,
                        'bg-amber-600 text-white hover:bg-amber-500' => $showOutOfShift,
                    ])
                >
                    <x-heroicon-m-exclamation-triangle class="h-4 w-4" />
                    {{ $this->outOfShiftTotal }}
                    {{ $this->outOfShiftTotal === 1 ? 'pedido' : 'pedidos' }}
                    de turnos anteriores
                    <span class="font-normal opacity-75">
                        ({{ $showOutOfShift ? 'ocultar' : 'mostrar' }})
                    </span>
                </button>
            @endif

            {{-- Colunas visíveis — preferência gravada no usuário. --}}
            <div x-data="{ aberto: false }" class="relative" @click.outside="aberto = false">
                <button
                    type="button"
                    @click="aberto = ! aberto"
                    class="flex items-center gap-1.5 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10"
                >
                    <x-heroicon-m-view-columns class="h-4 w-4" />
                    Colunas
                    @php $hiddenCount = count($this->availableColumns()) - count($columns); @endphp
                    @if ($hiddenCount > 0)
                        <span class="font-normal opacity-75">({{ $hiddenCount }} {{ $hiddenCount === 1 ? 'oculta' : 'ocultas' }})</span>
                    @endif
                </button>

                <div
                    x-show="aberto"
                    x-cloak
                    class="absolute right-0 z-20 mt-1 w-56 rounded-lg bg-white p-2 shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                >
                    @foreach ($this->availableColumns() as $column)
                        <label class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5">
                            <input
                                type="checkbox"
                                @checked(! in_array($column->value, $hiddenColumns, true))
                                wire:click="toggleColumn('{{ $column->value }}')"
                                class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-white/20 dark:bg-gray-800"
                            >
                            {{ $this->columnLabel($column) }}
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        @if ($showOutOfShift)
            <div class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/30">
                Mostrando também pedidos abertos antes do turno atual. Eles ficaram presos em alguma etapa — vale conferir se devem ser concluídos ou cancelados.
            </div>
        @endif

        {{-- Faixa de colunas: rola na horizontal com snap até xl, onde vira grid. O número de colunas é
             dinâmico (a coluna "Em Entrega" sai quando o tenant não usa o estágio), então o
             grid-template-columns vem de uma custom property — o Tailwind não gera classe a partir de
             valor calculado em runtime. --}}
        <div
            class="flex snap-x snap-mandatory gap-2 overflow-x-auto overscroll-x-contain pb-2 xl:grid xl:snap-none xl:[grid-template-columns:repeat(var(--painel-colunas),minmax(19rem,1fr))]"
            style="--painel-colunas: {{ count($columns) }}"
        >
            @foreach ($columns as $column)
                @include('filament.tenant.pages.kitchen.column', [
                    'status' => $column,
                    'orders' => $column === \App\Enums\OrderStatus::Delivered
                        ? $finishedOrders
                        : $this->ordersByStatus->get($column->value, collect()),
                    'canOpenAttendance' => $canOpenAttendance,
                ])
            @endforeach
        </div>

        {{-- Cancelados do turno --}}
        @if ($showCancelled)
            <div class="mt-3 rounded-xl border border-danger-200 bg-danger-50/50 p-3 dark:border-danger-500/20 dark:bg-danger-500/5">
                <div class="mb-3 flex items-center justify-between px-1">
                    <span class="text-sm font-bold text-danger-700 dark:text-danger-400">Cancelados neste turno</span>
                    <span class="rounded-full bg-danger-100 px-2 py-0.5 text-xs font-semibold text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                        {{ $this->cancelledOrders->count() }}
                    </span>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @forelse ($this->cancelledOrders as $order)
                        @include('filament.tenant.pages.kitchen.cancelled-card', ['order' => $order])
                    @empty
                        <p class="px-1 text-xs text-gray-400">Nenhum pedido cancelado neste turno.</p>
                    @endforelse
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
