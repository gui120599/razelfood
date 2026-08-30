<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
        <div class="space-y-4 xl:col-span-3">
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <h2 class="mb-3 flex items-center gap-2 font-heading text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <x-heroicon-o-squares-2x2 class="h-4 w-4" />
                    Catálogo
                </h2>
                @livewire('tenant-order-product-catalog', key('attend-order-catalog'))
            </div>
        </div>

        <div class="space-y-4 xl:col-span-2">
            @if ($errorMessage)
                <div class="rounded-lg border border-rf-danger/40 bg-rf-danger/10 px-4 py-3 text-sm text-rf-danger">
                    {{ $errorMessage }}
                </div>
            @endif

            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <h2 class="mb-3 flex items-center gap-2 font-heading text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <x-heroicon-o-shopping-cart class="h-4 w-4" />
                    Carrinho
                </h2>

                @if (empty($this->cartLines))
                    <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-500">Nenhum item adicionado ainda.</p>
                @else
                    <ul class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($this->cartLines as $line)
                            <li wire:key="cart-line-{{ $line['index'] }}" class="flex items-start justify-between gap-3 py-2.5">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-1.5">
                                        @if ($line['category_name'])
                                            <span class="rounded-full border border-gray-200 bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-gray-500 dark:border-white/10 dark:bg-white/10 dark:text-gray-400">{{ $line['category_name'] }}</span>
                                        @endif
                                        @if ($line['is_combo'])
                                            <span class="rounded-full border border-rf-teal-500/30 bg-rf-teal-500/10 px-1.5 py-0.5 text-[10px] font-bold uppercase text-rf-teal-500 dark:text-rf-teal-300">Combo</span>
                                        @endif
                                        <span class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $line['name'] }}</span>
                                    </div>

                                    <div class="mt-1 flex items-center gap-2">
                                        <button type="button" wire:click="updateItemQuantity({{ $line['index'] }}, {{ $line['quantity'] - 1 }})" class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-white/20">
                                            <x-heroicon-o-minus class="h-3 w-3" />
                                        </button>
                                        <span class="w-5 text-center text-sm text-gray-700 dark:text-gray-200">{{ $line['quantity'] }}</span>
                                        <button type="button" wire:click="updateItemQuantity({{ $line['index'] }}, {{ $line['quantity'] + 1 }})" class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-white/20">
                                            <x-heroicon-o-plus class="h-3 w-3" />
                                        </button>

                                        <span class="text-xs text-gray-500 dark:text-gray-500">{{ \Illuminate\Support\Number::currency($line['unit_price'], in: 'BRL', locale: 'pt_BR') }} cada</span>
                                    </div>

                                    @foreach ($line['addons_display'] as $addonLine)
                                        <p class="mt-0.5 truncate text-xs text-gray-400 dark:text-gray-500">+ {{ $addonLine }}</p>
                                    @endforeach

                                    <div x-data="{ open: @js(filled($line['note'])) }" class="mt-1">
                                        <button
                                            type="button"
                                            x-show="! open"
                                            x-on:click="open = true; $nextTick(() => $refs.noteInput?.focus())"
                                            class="flex items-center gap-1 text-xs font-medium text-rf-orange-600 hover:text-rf-orange-700"
                                        >
                                            <x-heroicon-o-chat-bubble-left-ellipsis class="h-3.5 w-3.5" />
                                            Observação
                                        </button>

                                        <input
                                            x-show="open"
                                            x-ref="noteInput"
                                            type="text"
                                            value="{{ $line['note'] }}"
                                            wire:change="updateItemNote({{ $line['index'] }}, $event.target.value)"
                                            placeholder="Obs.: sem cebola, bem passado..."
                                            class="fi-input block w-full rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs text-gray-900 placeholder:text-gray-400 focus:ring-2 focus:ring-rf-orange-600 dark:border-none dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                                        >
                                    </div>
                                </div>

                                <div class="flex flex-col items-end gap-1.5">
                                    <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ \Illuminate\Support\Number::currency($line['line_total'], in: 'BRL', locale: 'pt_BR') }}</span>
                                    <button type="button" wire:click="removeItem({{ $line['index'] }})" class="text-gray-400 hover:text-rf-danger">
                                        <x-heroicon-o-trash class="h-4 w-4" />
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div x-data="{ open: false }" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <button type="button" x-on:click="open = ! open" class="flex w-full items-center justify-between">
                    <h2 class="flex items-center gap-2 font-heading text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <x-heroicon-o-user class="h-4 w-4" />
                        Cliente
                    </h2>
                    <x-heroicon-o-chevron-down x-bind:class="open ? 'rotate-180' : ''" class="h-4 w-4 text-gray-400 transition-transform dark:text-gray-500" />
                </button>

                <div
                    x-show="open"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    class="mt-3"
                >
                    @livewire('tenant-order-client-lookup', ['initial' => $clientData], key('attend-order-client'))
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <h2 class="mb-3 flex items-center gap-2 font-heading text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <x-heroicon-o-truck class="h-4 w-4" />
                    Entrega e pagamento
                </h2>
                @livewire('tenant-order-fulfillment-picker', ['initial' => $fulfillmentData, 'total' => $this->grandTotalPreview], key('attend-order-fulfillment'))

                @if ($preview = $this->deliveryFeePreview)
                    @if ($preview['blocked'])
                        <p class="mt-3 text-xs text-rf-danger">{{ $preview['message'] }}</p>
                    @endif
                @endif
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <h2 class="mb-3 flex items-center gap-2 font-heading text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <x-heroicon-o-chat-bubble-left-ellipsis class="h-4 w-4" />
                    Observação do pedido
                </h2>
                <textarea
                    wire:model.live.debounce.500ms="orderNotes"
                    rows="2"
                    placeholder="Ex.: entregar na portaria, sem cebola em tudo, etc."
                    class="fi-input block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-2 focus:ring-rf-orange-600 dark:border-none dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                ></textarea>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between text-gray-500 dark:text-gray-400">
                        <dt>Itens</dt>
                        <dd>{{ \Illuminate\Support\Number::currency($this->cartTotal, in: 'BRL', locale: 'pt_BR') }}</dd>
                    </div>
                    <div class="flex justify-between text-gray-500 dark:text-gray-400">
                        <dt>Entrega</dt>
                        <dd>{{ \Illuminate\Support\Number::currency($this->deliveryFeePreviewAmount, in: 'BRL', locale: 'pt_BR') }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-gray-200 pt-1.5 font-heading text-base font-bold text-rf-orange-600 dark:border-white/10">
                        <dt>Total</dt>
                        <dd>{{ \Illuminate\Support\Number::currency($this->grandTotalPreview, in: 'BRL', locale: 'pt_BR') }}</dd>
                    </div>
                </dl>

                <button
                    type="button"
                    wire:click="save"
                    wire:loading.attr="disabled"
                    class="mt-4 w-full rounded-lg bg-rf-orange-600 py-3 text-sm font-semibold text-white transition hover:bg-rf-orange-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {{ $currentOrder ? 'Salvar alterações' : 'Criar pedido' }}
                </button>
            </div>
        </div>
    </div>

    @livewire('tenant-order-flavor-picker', key('attend-order-flavor-picker'))
    @livewire('tenant-order-addon-picker', key('attend-order-addon-picker'))
</x-filament-panels::page>
