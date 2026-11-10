{{--
    Uma coluna do kanban da Central de Pedidos (cópia traduzida da coluna do Painel de Pedidos original).

    Em telas grandes as colunas viram grid; abaixo disso a faixa rola na horizontal com scroll-snap, e
    cada coluna ocupa quase a largura da tela. Uma markup só, sem duplicar o card por breakpoint.

    A última coluna ("Finalizados") usa Delivered como marcador, mas mostra Entregue + Finalizado juntos.
    A ação primária é decidida POR PEDIDO (Kitchen::primaryActionName/Label), não pela coluna: em
    "Prontos" convivem delivery (despacha) e balcão (finaliza).
--}}
@php
    use App\Enums\OrderStatus;

    $isFinished = $status === OrderStatus::Delivered;
    $isStarted = $status === OrderStatus::Started;
    $tone = $this->columnTone($status);
@endphp

<section
    id="coluna-{{ Str::slug($status->value) }}"
    class="flex min-h-0 w-[88vw] shrink-0 snap-start flex-col sm:w-[46vw] lg:w-[32vw] xl:w-auto"
>
    {{-- Cabeçalho da coluna --}}
    <header
        @class([
            'flex items-center justify-between gap-2 rounded-t-xl px-3 py-2',
            'bg-gray-100 dark:bg-white/5' => $tone === 'gray',
            'bg-info-100 dark:bg-info-500/15' => $tone === 'info',
            'bg-warning-100 dark:bg-warning-500/15' => $tone === 'warning',
            'bg-success-100 dark:bg-success-500/15' => $tone === 'success',
        ])
    >
        <div class="flex min-w-0 items-center gap-1.5">
            <x-filament::icon :icon="'heroicon-o-'.$status->icon()" class="h-4 w-4 shrink-0 text-gray-600 dark:text-gray-300" />
            <h2 class="truncate text-sm font-bold text-gray-950 dark:text-white">
                {{ $this->columnLabel($status) }}
            </h2>
        </div>

        <span class="shrink-0 rounded-full bg-white/70 px-2 py-0.5 text-xs font-bold tabular-nums text-gray-700 dark:bg-gray-900/50 dark:text-gray-200">
            {{ $orders->count() }}
        </span>
    </header>

    {{-- Filtro de data, só na coluna final: o turno cujos pedidos finalizados ela mostra. --}}
    @if ($isFinished)
        <div class="flex items-center gap-2 border-x border-gray-200 bg-gray-50 px-3 py-1.5 dark:border-white/10 dark:bg-white/5">
            <label for="finishedDate" class="text-xs text-gray-500 dark:text-gray-400">Turno de</label>
            <input
                id="finishedDate"
                type="date"
                wire:model.live.debounce.700ms="finishedDate"
                class="min-w-0 flex-1 rounded-md border-gray-300 bg-white py-1 text-xs dark:border-white/10 dark:bg-gray-900 dark:text-white"
            />
        </div>
    @endif

    {{-- Corpo: rolagem independente por coluna. dvh, não vh — a barra de endereço do mobile muda a altura visível. --}}
    <div class="h-[calc(100dvh-15rem)] flex-1 space-y-2 overflow-y-auto rounded-b-xl border border-gray-200 bg-gray-50 p-2 dark:border-white/10 dark:bg-white/[0.02]">
        @forelse ($orders as $order)
            <div wire:key="order-{{ $order->id }}">
                <x-orders.order-card
                    :order="$order"
                    :urgency="$this->urgencyFor($order)"
                    :minutes-in-stage="$this->minutesInStageFor($order)"
                    :primary-action="$this->primaryActionName($order)"
                    :primary-label="$this->primaryActionLabel($order)"
                    :cancel-label="$this->cancelLabel($order)"
                    :can-open-attendance="$canOpenAttendance"
                />
            </div>
        @empty
            <p class="px-2 py-6 text-center text-xs text-gray-400">
                @if ($isStarted)
                    Nenhum pedido aguardando.
                @elseif ($isFinished)
                    Nenhum pedido finalizado neste turno.
                @else
                    Nada aqui.
                @endif
            </p>
        @endforelse

        @if ($isFinished && $this->canLoadMoreFinished())
            <button
                type="button"
                wire:click="expandFinished"
                class="w-full rounded-lg py-2 text-xs font-semibold text-primary-600 transition hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-500/10"
            >
                Carregar mais finalizados
            </button>
        @endif

        @if ($isFinished && $this->finishedIsTruncated())
            <p class="px-1 py-1 text-[11px] text-gray-400">
                Mostrando os {{ $orders->count() }} mais recentes deste turno. O resto está no histórico.
            </p>
        @endif

        @if ($isFinished)
            <a
                href="{{ \App\Filament\Tenant\Resources\Orders\OrderResource::getUrl('index') }}"
                class="block px-1 py-1 text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400"
            >
                Ver histórico completo →
            </a>
        @endif
    </div>
</section>
