<x-filament-panels::page>
    <div
        x-data="{
            discardUrlTemplate: @js(route('sales.discard_empty', ['sale' => '__SALE__'])),
            csrfToken: @js(csrf_token()),
        }"
        x-init="
            window.addEventListener('beforeunload', () => {
                const id = $wire.saleId;
                if (id) {
                    navigator.sendBeacon(discardUrlTemplate.replace('__SALE__', id), new URLSearchParams({ _token: csrfToken }));
                }
            });
        "
        @keydown.window.alt.c.prevent="$wire.openClientModal()"
        @keydown.window.alt.p.prevent="$wire.openPaymentModal()"
        @keydown.window.alt.e.prevent="$wire.set('activeTab', 'orders')"
        @keydown.window.alt.o.prevent="$wire.set('activeTab', 'products')"
        @keydown.window.escape="$wire.closeClientModal(); $wire.closePaymentModal(); $wire.closeCancelModal(); $wire.closeSplitModal()"
    >
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
        {{-- Coluna de fontes de itens: abas Pedidos / Produtos --}}
        <div class="xl:col-span-3 space-y-4">
            <div class="flex items-center gap-2">
                @foreach (['orders' => 'Pedidos', 'products' => 'Produtos'] as $aba => $label)
                    <button
                        x-on:click="$wire.set('activeTab', '{{ $aba }}').then(() => $nextTick(() => document.querySelector('[data-busca-input]')?.focus()))"
                        type="button"
                        class="px-4 py-2 rounded-lg text-sm font-semibold border transition-colors
                            {{ $activeTab === $aba ? 'bg-primary-600 border-primary-600 text-white' : 'border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:border-gray-400 dark:hover:border-white/20' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach

                @if ($activeTab !== $this->defaultTab())
                    <button
                        wire:click="setDefaultTab"
                        type="button"
                        title="Definir '{{ $activeTab === 'orders' ? 'Pedidos' : 'Produtos' }}' como aba padrão ao abrir esta página"
                        class="ml-auto flex items-center gap-1 text-xs font-medium text-gray-500 dark:text-gray-400 hover:underline shrink-0"
                    >
                        <x-filament::icon icon="heroicon-o-bookmark" class="h-3.5 w-3.5" />
                        Definir como padrão
                    </button>
                @endif

                @if ($activeTab === 'orders')
                    <button
                        wire:click="toggleOpenCardsByDefault"
                        type="button"
                        class="{{ $activeTab === $this->defaultTab() ? 'ml-auto' : '' }} text-xs font-medium text-gray-500 dark:text-gray-400 hover:underline shrink-0"
                    >
                        {{ $this->openCardsByDefault ? 'Ocultar itens por padrão' : 'Mostrar itens por padrão' }}
                    </button>
                @endif
            </div>

            @if ($activeTab === 'products')
                @if ($stockError)
                    <div class="flex items-start gap-2 px-4 py-3 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 rounded-xl text-red-700 dark:text-red-400 text-sm">
                        <x-filament::icon icon="heroicon-o-exclamation-circle" class="h-4 w-4 mt-0.5 shrink-0" />
                        <span class="flex-1">{{ $stockError }}</span>
                        <button wire:click="closeStockToast" type="button" class="text-red-400 hover:text-red-600 shrink-0">
                            <x-filament::icon icon="heroicon-o-x-mark" class="h-4 w-4" />
                        </button>
                    </div>
                @endif
                @livewire('tenant-sale-product-selector', key('new-sale-product-selector'))
            @elseif ($activeTab === 'orders')
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-gray-500 pointer-events-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input
                        wire:model.live.debounce.300ms="orderSearch"
                        type="text"
                        data-busca-input
                        placeholder="Buscar por número do pedido ou cliente..."
                        class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-xl bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                    />
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 divide-y divide-gray-100 dark:divide-white/10 overflow-y-auto" style="max-height: clamp(20rem, 65vh, 52rem)">
                    @forelse ($this->availableOrders as $order)
                        @php $orderLaunched = $this->orderIsLaunched($order); @endphp
                        <div wire:key="order-{{ $order->id }}" class="p-4 space-y-2" x-data="{ aberto: @js($this->openCardsByDefault) }">
                            <div class="flex items-center justify-between gap-2">
                                <button type="button" x-on:click="aberto = !aberto" class="flex items-center gap-2 text-left min-w-0">
                                    <x-filament::icon icon="heroicon-o-chevron-right" x-bind:class="aberto ? 'rotate-90' : ''" class="h-3.5 w-3.5 text-gray-400 dark:text-gray-500 transition-transform shrink-0" />
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-200 truncate">Pedido #{{ $order->id }}</p>
                                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ $order->client?->name ?? 'Sem cliente' }} · R$ {{ number_format((float) $order->grand_total, 2, ',', '.') }}</p>
                                    </div>
                                </button>
                                <button type="button"
                                    onclick="window.open('{{ route('order.ticket', ['order' => $order->id]) }}', '_blank', 'width=600,height=400')"
                                    title="Imprimir pedido"
                                    class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 shrink-0">
                                    <x-filament::icon icon="heroicon-o-printer" class="h-4 w-4" />
                                </button>
                                <label class="flex items-center gap-2 shrink-0 cursor-pointer select-none">
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Lançado</span>
                                    <input
                                        type="checkbox"
                                        wire:loading.attr="disabled"
                                        @checked($orderLaunched)
                                        x-on:change="$event.target.checked ? $wire.launchOrder({{ $order->id }}) : $wire.removeOrder({{ $order->id }})"
                                        class="h-4 w-4 rounded border-gray-300 dark:border-white/20 dark:bg-gray-900 text-primary-600 focus:ring-primary-500 disabled:opacity-50"
                                    />
                                </label>
                            </div>

                            <div x-show="aberto" x-cloak class="pl-6 space-y-1">
                                @foreach ($order->items as $orderItem)
                                    @include('filament.tenant.pages.sales.new-sale.order-item', ['orderItem' => $orderItem])
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="p-10 text-center text-sm text-gray-400 dark:text-gray-500">Nenhum pedido pendente.</div>
                    @endforelse
                    @if ($this->ordersTruncated)
                        <div class="p-3 text-center text-xs text-gray-400 dark:text-gray-500">Mostrando os 50 pedidos mais recentes. Use a busca para encontrar os demais.</div>
                    @endif
                </div>
            @endif
        </div>

        {{-- Coluna da venda atual: carrinho, cliente, pagamento, totais --}}
        <div class="xl:col-span-2 space-y-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $this->saleId ? 'Rascunho #'.$this->saleId : 'Nova venda' }}</h3>
                </div>

                <div class="divide-y divide-gray-100 dark:divide-white/10 overflow-y-auto" style="max-height: clamp(16rem, 45vh, 36rem)">
                    @forelse ($this->cartLines as $line)
                        <div wire:key="cart-item-{{ $line['key'] }}" class="px-4 py-3 space-y-2">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    @include('filament.tenant.pages.sales.new-sale.item-name', ['line' => $line, 'class' => 'block text-sm font-medium text-gray-800 dark:text-gray-200'])
                                </div>
                                <button wire:click="removeCartLine('{{ $line['kind'] }}', {{ $line['id'] }})" type="button" class="text-gray-400 dark:text-gray-500 hover:text-red-600 dark:hover:text-red-400 shrink-0">
                                    <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" />
                                </button>
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <button
                                        wire:click="changeLineQuantity('{{ $line['kind'] }}', {{ $line['id'] }}, {{ max(0, $line['quantity'] - 1) }})"
                                        type="button"
                                        class="w-7 h-7 rounded-full border border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:border-gray-400 dark:hover:border-white/20 flex items-center justify-center"
                                    >
                                        <x-filament::icon icon="heroicon-o-minus" class="h-3.5 w-3.5" />
                                    </button>
                                    <span class="text-sm font-semibold w-8 text-center text-gray-800 dark:text-gray-200">{{ $line['quantity'] }}</span>
                                    <button
                                        wire:click="changeLineQuantity('{{ $line['kind'] }}', {{ $line['id'] }}, {{ $line['quantity'] + 1 }})"
                                        type="button"
                                        class="w-7 h-7 rounded-full border border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:border-gray-400 dark:hover:border-white/20 flex items-center justify-center"
                                    >
                                        <x-filament::icon icon="heroicon-o-plus" class="h-3.5 w-3.5" />
                                    </button>
                                </div>

                                <div class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                    <span>Desc. R$</span>
                                    <input
                                        type="text"
                                        inputmode="decimal"
                                        value="{{ number_format($line['discount_cents'] / 100, 2, ',', '.') }}"
                                        x-on:input="$el.value = Currency.masking($el.value, {locales:'pt-BR'})"
                                        x-on:change="$wire.updateLineDiscount('{{ $line['kind'] }}', {{ $line['id'] }}, Number($el.value.replace(/\D/g, '')) / 100).then(() => $el.value = '{{ number_format($line['discount_cents'] / 100, 2, ',', '.') }}')"
                                        class="w-16 text-xs border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-md px-1.5 py-1 text-right focus:outline-none focus:ring-1 focus:ring-primary-500"
                                    />
                                </div>

                                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">
                                    R$ {{ number_format($line['total_cents'] / 100, 2, ',', '.') }}
                                </span>
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-10 text-center text-sm text-gray-400 dark:text-gray-500">
                            Nenhum item lançado ainda.
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 p-4 flex items-center justify-between gap-3">
                @if ($this->sale?->client)
                    <div class="min-w-0">
                        <p class="text-xs text-gray-400 dark:text-gray-500">Cliente</p>
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-200 truncate">{{ $this->sale->client->name }}</p>
                    </div>
                    <div class="flex gap-2 shrink-0">
                        <button wire:click="openClientModal" type="button" class="text-xs text-primary-700 dark:text-primary-400 hover:underline">Trocar</button>
                        <button wire:click="removeClient" type="button" class="text-xs text-red-600 dark:text-red-400 hover:underline">Remover</button>
                    </div>
                @else
                    <span class="text-sm text-gray-400 dark:text-gray-500">Nenhum cliente vinculado</span>
                    <button wire:click="openClientModal" type="button" class="text-xs font-semibold text-primary-700 dark:text-primary-400 hover:underline shrink-0">Selecionar cliente</button>
                @endif
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Desconto percentual</span>
                    @if ($this->sale?->discount_percent)
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ rtrim(rtrim(number_format((float) $this->sale->discount_percent, 2, ',', '.'), '0'), ',') }}%</span>
                            <button wire:click="clearDiscountPercent" type="button" class="text-xs text-red-600 dark:text-red-400 hover:underline">Desfazer</button>
                        </div>
                    @else
                        <form wire:submit="applyDiscountPercent($refs.percentual.value)" class="flex items-center gap-2">
                            <input x-ref="percentual" type="number" step="0.01" min="0" max="100" placeholder="%" class="w-16 text-xs border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-md px-1.5 py-1 text-right focus:outline-none focus:ring-1 focus:ring-primary-500" />
                            <button type="submit" class="text-xs font-semibold text-primary-700 dark:text-primary-400 hover:underline">Aplicar</button>
                        </form>
                    @endif
                </div>

                <div class="flex items-center justify-between gap-3">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Frete</span>
                    <div class="flex items-center gap-1">
                        <span class="text-xs text-gray-400 dark:text-gray-500">R$</span>
                        <input
                            type="text"
                            inputmode="decimal"
                            value="{{ number_format((float) $this->sale?->delivery_fee, 2, ',', '.') }}"
                            x-on:input="$el.value = Currency.masking($el.value, {locales:'pt-BR'})"
                            x-on:change="$wire.updateDeliveryFee(Number($el.value.replace(/\D/g, '')) / 100)"
                            class="w-20 text-xs border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-md px-1.5 py-1 text-right focus:outline-none focus:ring-1 focus:ring-primary-500"
                        />
                    </div>
                </div>


                <div class="border-t border-gray-100 dark:border-white/10 pt-3 flex items-center justify-between">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Total</span>
                    <span class="text-lg font-bold text-gray-900 dark:text-white">R$ {{ number_format((float) $this->sale?->total, 2, ',', '.') }}</span>
                </div>
                <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                    <span>Pago</span>
                    <span>R$ {{ number_format($this->paidCents / 100, 2, ',', '.') }}</span>
                </div>
                <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                    <span>Troco</span>
                    <span>R$ {{ number_format($this->changeCents / 100, 2, ',', '.') }}</span>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Pagamentos</h3>
                    <div class="flex items-center gap-3">
                        <button wire:click="openSplitModal" type="button" class="text-xs font-semibold text-gray-500 dark:text-gray-400 hover:underline">Dividir conta</button>
                        <button wire:click="openPaymentModal" type="button" class="text-xs font-semibold text-primary-700 dark:text-primary-400 hover:underline">Adicionar</button>
                    </div>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($this->payments as $payment)
                        <div wire:key="payment-{{ $payment->id }}" class="px-4 py-2 flex items-center justify-between gap-2">
                            <span class="min-w-0 text-sm text-gray-700 dark:text-gray-300">
                                {{ $payment->payment_option_name }}
                            </span>
                            <div class="flex items-center gap-3">
                                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">R$ {{ number_format((float) $payment->amount, 2, ',', '.') }}</span>
                                <button wire:click="editPayment({{ $payment->id }})" type="button" class="text-gray-400 dark:text-gray-500 hover:text-primary-600 dark:hover:text-primary-400">
                                    <x-filament::icon icon="heroicon-o-pencil" class="h-4 w-4" />
                                </button>
                                <button wire:click="removePayment({{ $payment->id }})" type="button" class="text-gray-400 dark:text-gray-500 hover:text-red-600 dark:hover:text-red-400">
                                    <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-6 text-center text-sm text-gray-400 dark:text-gray-500">Nenhum pagamento lançado.</div>
                    @endforelse
                </div>
            </div>

            @error('finalize')
                <div class="bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-400 text-sm rounded-lg px-4 py-3">{{ $message }}</div>
            @enderror

            <div class="flex gap-2">
                <button wire:click="finalizeSale" wire:loading.attr="disabled" type="button" class="flex-1 py-3 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-50">
                    Finalizar venda
                </button>
                <button wire:click="openCancelModal" type="button" class="px-4 py-3 rounded-lg text-sm font-semibold border border-red-300 dark:border-red-500/30 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10">
                    Cancelar
                </button>
            </div>
        </div>
    </div>

    {{-- Modal Cancelar --}}
    @if ($cancelModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="closeCancelModal">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-sm">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Cancelar venda</h3>
                    <button wire:click="closeCancelModal" type="button" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>
                <div class="p-4 space-y-3">
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Motivo do cancelamento</label>
                    <select wire:model="cancellationNote" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
                        <option value="">Selecione...</option>
                        @foreach ($this->cancellationReasons as $label)
                            <option value="{{ $label }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <button wire:click="confirmCancellation" type="button" class="w-full py-2 rounded-lg text-sm font-semibold bg-red-600 text-white hover:bg-red-700">
                        Confirmar cancelamento
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal Emissão de NFC-e --}}
    @if ($fiscalModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-sm">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Emissão de NFC-e</h3>
                </div>
                <div class="p-4 space-y-3">
                    @if ($fiscalNotice)
                        <div class="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 text-amber-700 dark:text-amber-400 text-xs rounded-lg px-3 py-2">
                            {{ $fiscalNotice }}
                        </div>
                    @endif
                    @if ($fiscalStatus === 'processing')
                        <div wire:poll.3s="checkFiscalStatus" class="flex flex-col items-center gap-3 py-4 text-center">
                            <svg class="animate-spin h-6 w-6 text-primary-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <p class="text-sm text-gray-600 dark:text-gray-300">Emitindo NFC-e, aguarde...</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500">A emissão é processada de forma assíncrona pela NFE.io — isso pode levar alguns segundos.</p>
                        </div>
                        <button wire:click="closeFiscalModal" type="button" class="w-full py-2 rounded-lg text-sm font-semibold border border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5">
                            Continuar mesmo assim
                        </button>
                    @elseif ($fiscalStatus === 'error')
                        <div class="bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-400 text-sm rounded-lg px-3 py-2">
                            {{ $fiscalError }}
                        </div>
                        @if ($fiscalAmbiguous)
                            <div class="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 text-amber-700 dark:text-amber-400 text-xs rounded-lg px-3 py-2">
                                O envio pode ter chegado à NFE.io. Confira lá antes de reenviar para não duplicar a nota.
                            </div>
                        @endif
                        <div class="flex gap-2">
                            @can('emit_fiscal_document')
                                <button wire:click="retryFiscalIssue" @if ($fiscalAmbiguous) wire:confirm="O envio anterior pode ter chegado à NFE.io. Reenviar pode duplicar a nota — confira na NFE.io antes. Reenviar mesmo assim?" @endif type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700">
                                    Tentar novamente
                                </button>
                            @endcan
                            <button wire:click="closeFiscalModal" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold border border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5">
                                Continuar sem NFC-e
                            </button>
                        </div>
                    @elseif ($fiscalStatus === 'issued')
                        <div class="flex flex-col items-center gap-2 py-2 text-center">
                            <x-filament::icon icon="heroicon-o-check-circle" class="h-8 w-8 text-emerald-500" />
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-200">NFC-e emitida com sucesso!</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button"
                                wire:click="openDanfe"
                                class="flex-1 py-2 rounded-lg text-sm font-semibold border border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5">
                                Ver DANFE
                            </button>
                            <button wire:click="closeFiscalModal" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700">
                                Concluir
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Modal Cliente --}}
    @if ($clientModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="closeClientModal">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-lg max-h-[85vh] overflow-y-auto">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Selecionar cliente</h3>
                    <button wire:click="closeClientModal" type="button" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>

                <div class="p-4 space-y-4">
                    <div>
                        <input
                            wire:model.live.debounce.300ms="clientSearch"
                            type="text"
                            placeholder="Buscar por nome, CPF, CNPJ ou telefone..."
                            class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500"
                        />

                        @if ($this->foundClients->isNotEmpty())
                            <div class="mt-2 border border-gray-100 dark:border-white/10 rounded-lg divide-y divide-gray-100 dark:divide-white/10 max-h-48 overflow-y-auto">
                                @foreach ($this->foundClients as $client)
                                    <button
                                        wire:click="selectClient({{ $client->id }})"
                                        type="button"
                                        class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-white/5"
                                    >
                                        <span class="font-medium text-gray-800 dark:text-gray-200">{{ $client->name }}</span>
                                        <span class="text-xs text-gray-400 dark:text-gray-500">{{ $client->cpf ?? $client->cnpj ?? $client->phone }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @elseif (trim($clientSearch) !== '')
                            <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">Nenhum cliente encontrado.</p>
                        @endif
                    </div>

                    <div class="border-t border-gray-100 dark:border-white/10 pt-4 space-y-3">
                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Ou cadastre rápido por CPF/CNPJ</p>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="col-span-2">
                                <input wire:model="adHocName" type="text" placeholder="Nome" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                                @error('adHocName') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <input wire:model="adHocCpf" type="text" placeholder="CPF" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                            </div>
                            <div>
                                <input wire:model="adHocCnpj" type="text" placeholder="CNPJ" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                            </div>
                            <div>
                                <input wire:model="adHocPhone" type="text" placeholder="Telefone" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                            </div>
                            <div>
                                <input wire:model="adHocEmail" type="email" placeholder="E-mail" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                                @error('adHocEmail') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <button wire:click="saveAdHocClient" type="button" class="w-full py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700">
                            Cadastrar e vincular
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal Pagamento --}}
    @if ($paymentModalOpen)
        @php
            $selectedOption = $this->paymentOptions->firstWhere('id', $paymentOptionId);
            $editingPayment = (bool) $editingPaymentId;
            $noChange = ! ($selectedOption?->is_cash ?? false);
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="closePaymentModal">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-md max-h-[85vh] overflow-y-auto">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $editingPayment ? 'Editar pagamento' : 'Registrar pagamento' }}</h3>
                    <button wire:click="closePaymentModal" type="button" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>


                <div class="p-4 space-y-3">
                    @php $sugestaoPagamento = $this->suggestedPayment; @endphp
                    @if ($sugestaoPagamento && ! $editingPayment)
                        <div class="flex items-start gap-2 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 text-amber-800 dark:text-amber-300 text-xs rounded-lg p-2">
                            <x-filament::icon icon="heroicon-o-information-circle" class="h-4 w-4 shrink-0 mt-0.5" />
                            <div class="space-y-0.5">
                                <p>Forma de pagamento indicada no pedido: <span class="font-semibold">{{ $sugestaoPagamento['description'] }}</span></p>
                            </div>
                        </div>
                    @endif

                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Forma de pagamento</label>
                        <div class="grid grid-cols-2 gap-2 mt-1">
                            @foreach ($this->paymentOptions as $option)
                                <label class="relative flex items-center justify-center px-3 py-2 rounded-lg border text-sm font-medium text-center cursor-pointer transition-colors
                                    border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300
                                    has-[:checked]:border-primary-500 has-[:checked]:bg-primary-50 has-[:checked]:text-primary-700
                                    dark:has-[:checked]:bg-primary-500/10 dark:has-[:checked]:text-primary-400">
                                    <input type="radio" wire:model.live="paymentOptionId" value="{{ $option->id }}" class="sr-only" />
                                    {{ $option->name }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    @if ($selectedOption?->requires_card_brand)
                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Cartão / bandeira</label>
                            @if ($this->usesRegisteredCardBrands)
                                <select wire:model="cardBrandId" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500">
                                    <option value="">Selecione...</option>
                                    @foreach ($this->cardBrands as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input wire:model="cardBrandText" type="text" maxlength="60" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                            @endif
                        </div>
                    @endif

                    @if ($selectedOption?->requires_authorization_code)
                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Número de autorização</label>
                            <input
                                wire:model="authorizationCode"
                                type="text"
                                x-on:keydown.enter.prevent="$wire.registerPayment()"
                                class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500"
                            />
                        </div>
                    @endif

                    <div
                        class="grid grid-cols-2 gap-3"
                        wire:key="payment-values-{{ $editingPaymentId ?? 'new' }}-{{ $paymentOptionId }}"
                        x-data="{
                            recebido: @js(number_format($this->receivedAmount, 2, ',', '.')),
                            pagoCliente: @js($this->tenderedAmount > 0 ? number_format($this->tenderedAmount, 2, ',', '.') : ''),
                            parseValor(v) { return parseFloat(String(v).replace(/\./g, '').replace(',', '.')) || 0; },
                            get troco() { return Math.max(0, this.parseValor(this.pagoCliente) - this.parseValor(this.recebido)); },
                        }"
                    >
                        <div @class(['col-span-2' => $noChange])>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Valor recebido (R$)</label>
                            <input
                                type="text"
                                inputmode="decimal"
                                x-bind:value="recebido"
                                x-on:input="$el.value = Currency.masking($el.value, {locales:'pt-BR'}); recebido = $el.value"
                                x-on:blur="$wire.set('receivedAmount', parseValor(recebido))"
                                class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 text-right focus:outline-none focus:ring-2 focus:ring-primary-500"
                            />
                        </div>
                        @unless ($noChange)
                            <div>
                                <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Pago pelo cliente (R$)</label>
                                <input
                                    type="text"
                                    inputmode="decimal"
                                    x-bind:value="pagoCliente"
                                    x-on:input="$el.value = Currency.masking($el.value, {locales:'pt-BR'}); pagoCliente = $el.value"
                                    x-on:blur="$wire.set('tenderedAmount', parseValor(pagoCliente))"
                                    x-on:keydown.enter.prevent="$wire.set('tenderedAmount', parseValor(pagoCliente)).then(() => $wire.registerPayment())"
                                    class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 text-right focus:outline-none focus:ring-2 focus:ring-primary-500"
                                />
                            </div>
                        @endunless
                        <div class="col-span-2 flex items-center justify-between text-xs px-1">
                            <span class="text-gray-500 dark:text-gray-400">Restante: <strong class="text-gray-700 dark:text-gray-200">R$ {{ number_format($this->remainingCents / 100, 2, ',', '.') }}</strong></span>
                            @unless ($noChange)
                                <span class="text-gray-500 dark:text-gray-400">Troco: <strong class="text-green-600 dark:text-green-400" x-text="'R$ ' + troco.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2})"></strong></span>
                            @endunless
                        </div>
                    </div>

                    @error('receivedAmount')
                        <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror

                    <button wire:click="registerPayment" wire:loading.attr="disabled" type="button" class="w-full py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-50">
                        {{ $editingPayment ? 'Salvar alterações' : 'Confirmar pagamento' }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal Dividir conta --}}
    @if ($splitModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="closeSplitModal">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-sm">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Dividir conta</h3>
                    <button wire:click="closeSplitModal" type="button" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>
                <div class="p-4 space-y-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Restante a pagar: <strong class="text-gray-700 dark:text-gray-200">R$ {{ number_format($this->remainingCents / 100, 2, ',', '.') }}</strong>
                    </p>
                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Quantidade de pessoas</label>
                        <input wire:model.live="splitPeople" type="number" min="1" step="1" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Forma de pagamento padrão</label>
                        <select wire:model="splitOptionId" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500">
                            <option value="">Selecione...</option>
                            @foreach ($this->paymentOptions as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($splitPeople > 0)
                        <p class="text-xs text-gray-400 dark:text-gray-500">
                            {{ $splitPeople }}x de aproximadamente R$ {{ number_format($this->remainingCents / 100 / max(1, $splitPeople), 2, ',', '.') }}
                        </p>
                    @endif
                    <button wire:click="splitBill($wire.splitPeople, $wire.splitOptionId)" type="button" class="w-full py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700">
                        Confirmar divisão
                    </button>
                </div>
            </div>
        </div>
    @endif


    @assets
        <script src="{{ asset('js/app/money-script.js') }}"></script>
    @endassets
    </div>
</x-filament-panels::page>
