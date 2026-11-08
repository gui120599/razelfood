<x-filament-panels::page>
    @if ($this->paymentMismatch)
        <div class="flex items-start gap-2 px-4 py-3 mb-4 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 rounded-xl text-amber-700 dark:text-amber-400 text-sm">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0" />
            <span>Forma de pagamento combinada não bate com o total — revise e salve.</span>
        </div>
    @endif

    {{-- Tipo de atendimento: segue a opção de entrega escolhida (retirada não tem endereço nem taxa). --}}
    @php $serviceType = $this->serviceType; @endphp
    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
        <span>Tipo de atendimento</span>
        <span class="inline-flex min-h-8 items-center gap-1.5 rounded-full bg-primary-600 px-3 font-semibold text-white">
            <x-filament::icon :icon="$serviceType['icon']" class="h-4 w-4" />
            {{ $serviceType['label'] }}
        </span>
        @if ($currentOrder)
            <span class="text-gray-500 dark:text-gray-400">Pedido nº {{ $currentOrder->id }}</span>
        @endif
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-5 gap-4">
        <div class="xl:col-span-3">
            @livewire('tenant-order-product-catalog', key('attend-order-catalog'))
        </div>

        <div class="xl:col-span-2 space-y-4">
            {{-- Carrinho: card próprio da página, acima do Cliente. As ações (+/-, editar, remover) são da
                 própria AttendOrder, que é a dona do carrinho (a regra de preço continua no servidor). --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <h3 class="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <x-heroicon-o-shopping-cart class="h-4 w-4 text-primary-600" />
                    Carrinho
                    <span class="font-normal normal-case text-gray-400 dark:text-gray-500">({{ count($this->cartLines) }})</span>
                </h3>

                @if (empty($this->cartLines))
                    <p class="py-6 text-center text-sm text-gray-400 dark:text-gray-500">Nenhum item adicionado ainda.</p>
                @else
                    <div class="divide-y divide-gray-100 dark:divide-white/10 max-h-96 overflow-y-auto">
                        @foreach ($this->cartLines as $line)
                            @include('filament.tenant.pages.orders.attend-order.cart-line', ['line' => $line])
                        @endforeach
                    </div>
                @endif
            </div>

            @livewire('tenant-order-client-lookup', ['initial' => $clientData, 'requiresAddress' => $serviceType['requires_address']], key('attend-order-client'))

            @livewire('tenant-order-fulfillment-picker', ['initial' => $fulfillmentData, 'total' => $this->grandTotalPreview], key('attend-order-fulfillment'))

            @if (($preview = $this->deliveryFeePreview) && $preview['blocked'])
                <p class="px-1 text-xs text-rf-danger">{{ $preview['message'] }}</p>
            @endif

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-2">
                <label class="text-xs text-gray-500 dark:text-gray-400">Observação</label>
                <textarea wire:model.live.debounce.500ms="orderNotes" rows="2" placeholder="Alguma observação sobre o pedido..."
                    class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500"></textarea>
            </div>

            {{-- Resumo financeiro --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Resumo financeiro</h3>

                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between text-gray-600 dark:text-gray-300">
                        <dt>Itens</dt>
                        <dd>R$ {{ number_format($this->cartTotal, 2, ',', '.') }}</dd>
                    </div>
                    @if ($serviceType['requires_address'] || $this->deliveryFeePreviewAmount > 0)
                        <div class="flex justify-between text-gray-600 dark:text-gray-300">
                            <dt>Taxa de entrega</dt>
                            <dd>{{ $this->deliveryFeePreviewAmount > 0 ? 'R$ '.number_format($this->deliveryFeePreviewAmount, 2, ',', '.') : 'Grátis' }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between pt-1.5 mt-1 border-t border-gray-100 dark:border-white/10 text-base font-bold text-gray-800 dark:text-gray-100">
                        <dt>Total</dt>
                        <dd class="text-primary-600 dark:text-primary-400">R$ {{ number_format($this->grandTotalPreview, 2, ',', '.') }}</dd>
                    </div>
                </dl>

                <button type="button" wire:click="save" wire:loading.attr="disabled"
                    class="w-full rounded-lg bg-primary-600 py-2.5 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">
                    {{ $currentOrder ? 'Salvar alterações' : 'Criar pedido' }}
                </button>
            </div>
        </div>
    </div>

    @livewire('tenant-order-flavor-picker', key('attend-order-flavor-picker'))
    @livewire('tenant-order-addon-picker', key('attend-order-addon-picker'))
</x-filament-panels::page>
