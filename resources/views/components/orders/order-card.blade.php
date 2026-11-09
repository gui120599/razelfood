{{--
    Card de pedido da Central de Pedidos (cópia traduzida do card do Painel de Pedidos original).

    UM card para todas as colunas. O que varia por pedido:
    - endereço aparece quando o pedido é de entrega (opção de entrega que exige endereço);
    - entregador aparece quando há um atribuído;
    - o aviso "Já pago" aparece quando o pedido já foi cobrado numa venda;
    - o botão primário vem de $primaryAction / $primaryLabel (decididos por pedido na página).

    Fora desta cópia (ainda não existem no RazelFood): mesa, andamento e ações do módulo de produção
    da cozinha, selo de oferta de promoção adicional.
--}}
@props([
    'order',
    'urgency',
    'minutesInStage',
    'primaryAction',
    'primaryLabel',
    'cancelLabel',
    'canOpenAttendance' => false,
])

@php
    use App\Support\Orders\DurationFormatter;

    $urgencyBorder = match ($urgency->value) {
        'late' => 'border-l-4 border-l-red-500',
        'attention' => 'border-l-4 border-l-amber-400',
        default => 'border-l-4 border-l-transparent',
    };

    $fulfillment = $order->fulfillmentType();
    $isPaid = $order->sale_id !== null;
    $canManage = auth()->user()?->can('manage_order_status') ?? false;
    $canMarkDelivered = $order->canConfirmDeliveryBy(auth()->user());
    $trackingUrl = $order->trackingUrl();
    $canOpenInAttendance = $canOpenAttendance && $canManage && $order->isEditableInAttendanceBy(auth()->user());
@endphp

<div
    wire:key="order-card-{{ $order->id }}"
    {{ $attributes->class(["rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 {$urgencyBorder}"]) }}
>
    {{-- Área clicável: abre o modal de detalhes. Os botões de ação ficam fora dela, no rodapé,
         para não competir por clique. --}}
    <div
        wire:click="mountAction('viewDetails', { order: {{ $order->id }} })"
        class="cursor-pointer"
    >
        {{-- Cabeçalho: número, tipo de atendimento, tempo no status --}}
        <div class="flex items-start justify-between gap-2 px-3 pt-2.5">
            <div class="min-w-0">
                <span class="text-base font-bold text-gray-950 dark:text-white">#{{ $order->id }}</span>

                <span class="ml-1 inline-block rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-600 dark:bg-white/10 dark:text-gray-300">
                    {{ $fulfillment->label() }}
                </span>

                <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                    {{ $order->deliveryOption?->name }}
                    @if ($order->client_id)
                        @if ($order->deliveryOption) &middot; @endif
                        {{ $order->client?->name }}
                        @if ($order->client?->phone)
                            &middot; {{ $order->client->phone }}
                        @endif
                    @endif
                </div>
            </div>

            {{-- Tempo no status atual: antes o card mostrava só a hora de abertura e a cozinha calculava de cabeça. --}}
            <div class="shrink-0 text-right">
                @if ($minutesInStage !== null)
                    <span
                        @class([
                            'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums',
                            'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300' => $urgency->value === 'normal',
                            'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400' => $urgency->value === 'attention',
                            'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' => $urgency->value === 'late',
                        ])
                        title="{{ $urgency->label() }} neste status"
                    >
                        <x-heroicon-m-clock class="h-3.5 w-3.5" />
                        {{ DurationFormatter::minutes($minutesInStage) }}
                    </span>
                @endif

                @if ($order->opened_at)
                    <div class="mt-0.5 text-[11px] tabular-nums text-gray-400">
                        {{ $order->opened_at->format('H:i') }}
                    </div>
                @endif
            </div>
        </div>

        {{-- Endereço, só para quem sai para entrega --}}
        @if ($order->requiresDelivery() && $order->delivery_address)
            <div class="mt-2 flex items-start gap-1.5 px-3 text-xs text-gray-600 dark:text-gray-300">
                <x-heroicon-m-map-pin class="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" />
                <span>{{ $order->delivery_address }}</span>
            </div>
        @endif

        @if ($order->assigned_delivery_user_id && $order->assignedDeliveryUser)
            <div class="mt-1 flex items-center gap-1.5 px-3 text-xs font-medium text-gray-600 dark:text-gray-300">
                <x-heroicon-m-truck class="h-3.5 w-3.5 shrink-0 text-gray-400" />
                <span>{{ $order->assignedDeliveryUser->name }}</span>
            </div>
        @endif

        {{-- Itens --}}
        <ul class="mt-2 divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
            @foreach ($order->items as $item)
                <li class="flex gap-2 px-3 py-1.5">
                    <span class="w-6 shrink-0 text-center text-[13px] font-bold tabular-nums text-gray-950 dark:text-white">
                        {{ $item->quantity }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <span class="text-[13px] font-semibold uppercase leading-tight text-gray-950 dark:text-white">
                            @if ($item->product?->category?->name)
                                <span class="inline-block rounded border border-teal-200 bg-teal-50 px-1.5 py-0.5 align-middle text-[10px] font-semibold uppercase tracking-wide text-teal-700 dark:border-teal-500/20 dark:bg-teal-500/10 dark:text-teal-400">{{ $item->product->category->name }}</span>
                            @endif

                            @if (! empty($item->flavorLines))
                                @foreach ($item->flavorLines as $flavorLine)
                                    <span class="block">{{ $flavorLine }}</span>
                                @endforeach
                            @else
                                {{ $item->displayName }}
                            @endif
                        </span>

                        @foreach ($item->addonsDisplay ?? [] as $addonLine)
                            <div class="text-xs text-gray-500 dark:text-gray-400">+ {{ $addonLine }}</div>
                        @endforeach

                        @foreach ($item->giftsDisplay ?? [] as $giftLine)
                            <div class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">{{ $giftLine }}</div>
                        @endforeach

                        @if ($item->note)
                            <div class="text-xs font-medium text-amber-700 dark:text-amber-400">
                                OBS: {{ $item->note }}
                            </div>
                        @endif
                    </div>

                    <span class="shrink-0 text-xs font-semibold tabular-nums text-gray-500 dark:text-gray-400">
                        R$ {{ number_format(((float) $item->unit_price + (float) $item->addons_total) * $item->quantity, 2, ',', '.') }}
                    </span>
                </li>
            @endforeach
        </ul>

        @if ($isPaid)
            <div class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-success-700 dark:text-success-400">
                <x-heroicon-m-check-badge class="h-4 w-4 shrink-0" />
                Já pago &middot; venda {{ $order->sale?->displayNumber() }}
            </div>
        @endif

        @if ($urgency->value === 'late')
            <p class="mx-3 mb-2 rounded bg-red-50 px-2 py-1 text-xs font-bold text-red-600 dark:bg-red-500/10 dark:text-red-400">
                ATRASADO
                @if ($minutesInStage !== null)
                    &middot; {{ DurationFormatter::minutes($minutesInStage) }}
                @endif
            </p>
        @endif
    </div>{{-- fim da área clicável --}}

    {{-- Ações. Alvos de toque generosos: esta tela roda em tablet no balcão. Só a ação primária fica
         solta; o resto vai no menu ⋮ — menos risco de toque errado no tablet. Não uso
         <x-filament-actions::group>: o Filament 4.12 não propaga ->arguments() ao wire:click de cada
         item do grupo (cada botão resolveria a Action sem o argumento `order`). --}}
    <div class="flex items-stretch gap-1 border-t border-gray-100 p-2 dark:border-white/5">
        @if ($canManage || $canMarkDelivered)
            <div x-data="{ open: false }" class="relative shrink-0">
                <button
                    type="button"
                    x-on:click="open = !open"
                    x-on:click.outside="open = false"
                    class="flex h-11 w-11 items-center justify-center rounded-lg text-gray-500 ring-1 ring-gray-200 transition hover:bg-gray-50 dark:text-gray-400 dark:ring-white/10 dark:hover:bg-white/5"
                    title="Mais ações"
                >
                    <x-heroicon-o-ellipsis-vertical class="h-5 w-5" />
                </button>

                <div
                    x-show="open"
                    x-cloak
                    x-transition
                    class="absolute bottom-full left-0 z-10 mb-1 w-48 rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-white/10 dark:bg-gray-800"
                >
                    <button
                        type="button"
                        wire:click="mountAction('viewDetails', { order: {{ $order->id }} })"
                        x-on:click="open = false"
                        class="block w-full px-3 py-2 text-left text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                    >
                        Ver detalhes
                    </button>

                    @if ($canManage)
                        <a
                            href="{{ route('order.ticket', ['order' => $order->id]) }}"
                            target="_blank"
                            x-on:click="open = false"
                            class="block w-full px-3 py-2 text-left text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                        >
                            Imprimir pedido
                        </a>

                        @if ($trackingUrl)
                            {{-- Clipboard API só existe em contexto seguro (HTTPS/localhost): fora dele cai num prompt com o link. --}}
                            <button
                                type="button"
                                x-on:click="open = false; (window.navigator.clipboard?.writeText(@js($trackingUrl)) ?? Promise.reject()).then(() => new FilamentNotification().title('Link de acompanhamento copiado').success().send()).catch(() => window.prompt('Copie o link de acompanhamento:', @js($trackingUrl)))"
                                class="block w-full px-3 py-2 text-left text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                            >
                                Copiar link de acompanhamento
                            </button>
                        @endif

                        @if ($canOpenInAttendance)
                            <a
                                href="{{ \App\Filament\Tenant\Pages\Orders\AttendOrder::getUrl(['order' => $order->id]) }}"
                                x-on:click="open = false"
                                class="block w-full px-3 py-2 text-left text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                            >
                                Abrir no atendimento
                            </a>
                        @endif

                        @if ($order->allowsDeliveryLink())
                            <button
                                type="button"
                                wire:click="mountAction('deliveryLink', { order: {{ $order->id }} })"
                                x-on:click="open = false"
                                class="block w-full px-3 py-2 text-left text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                            >
                                Link de entrega
                            </button>
                        @endif

                        @if ($order->allowsCourierReassignment())
                            <button
                                type="button"
                                wire:click="mountAction('reassignDelivery', { order: {{ $order->id }} })"
                                x-on:click="open = false"
                                class="block w-full px-3 py-2 text-left text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                            >
                                Trocar entregador
                            </button>
                        @endif

                        @if ($order->status->canBeCancelled())
                            <button
                                type="button"
                                wire:click="mountAction('cancel', { order: {{ $order->id }} })"
                                x-on:click="open = false"
                                class="block w-full px-3 py-2 text-left text-xs text-danger-600 hover:bg-danger-50 dark:text-danger-400 dark:hover:bg-danger-500/10"
                            >
                                {{ $cancelLabel }} pedido
                            </button>
                        @endif
                    @endif
                </div>
            </div>
        @endif

        @if ($primaryAction === 'markDelivered')
            @if ($canMarkDelivered)
                {{-- wire:target COM os argumentos: sem eles o spinner acenderia em todos os cards da coluna. --}}
                <button
                    type="button"
                    wire:click="mountAction('markDelivered', { order: {{ $order->id }} })"
                    wire:target="mountAction('markDelivered', { order: {{ $order->id }} })"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-50 cursor-wait"
                    class="flex h-11 flex-1 items-center justify-center gap-1.5 rounded-lg bg-primary-600 px-3 text-sm font-semibold text-white transition hover:bg-primary-500 disabled:opacity-50"
                >
                    {{ $primaryLabel }}
                    <x-heroicon-m-arrow-right class="h-4 w-4" />
                </button>
            @endif
        @elseif ($primaryAction !== 'viewDetails')
            @if ($canManage)
                <button
                    type="button"
                    wire:click="mountAction('{{ $primaryAction }}', { order: {{ $order->id }} })"
                    wire:target="mountAction('{{ $primaryAction }}', { order: {{ $order->id }} })"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-50 cursor-wait"
                    class="flex h-11 flex-1 items-center justify-center gap-1.5 rounded-lg bg-primary-600 px-3 text-sm font-semibold text-white transition hover:bg-primary-500 disabled:opacity-50"
                >
                    {{ $primaryLabel }}
                    <x-heroicon-m-arrow-right class="h-4 w-4" />
                </button>
            @endif
        @endif
    </div>
</div>
