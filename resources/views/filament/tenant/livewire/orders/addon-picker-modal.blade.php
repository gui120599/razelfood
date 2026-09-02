<div>
    @if ($open)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/70 backdrop-blur-sm sm:items-center" x-data>
            <div
                class="flex max-h-[85vh] w-full flex-col overflow-hidden rounded-t-2xl border border-gray-200 bg-white sm:max-w-lg sm:rounded-2xl dark:border-white/10 dark:bg-gray-900"
                x-trap.noscroll="true"
            >
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <h3 class="font-heading text-base font-semibold text-gray-900 dark:text-white">Adicionais</h3>
                    <button type="button" wire:click="closeModal" class="text-gray-400 hover:text-gray-700 dark:hover:text-white">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                @if ($errorMessage)
                    <div class="mx-4 mt-3 rounded-lg bg-rf-danger/10 px-3 py-2 text-sm text-rf-danger">
                        {{ $errorMessage }}
                    </div>
                @endif

                <div class="flex-1 overflow-y-auto px-4 py-3">
                    @if ($wantsAddons === null)
                        <div class="flex flex-col items-center gap-4 py-6 text-center">
                            <p class="text-sm text-gray-600 dark:text-gray-300">Deseja adicionar algum adicional a este item?</p>
                            <div class="flex w-full gap-2">
                                <button
                                    type="button"
                                    wire:click="chooseWantsAddons(false)"
                                    class="flex-1 cursor-pointer rounded-lg border border-gray-200 bg-gray-50 py-2.5 text-sm font-semibold text-gray-600 transition hover:bg-gray-100 dark:border-white/10 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10"
                                >
                                    Não, pular
                                </button>
                                <button
                                    type="button"
                                    wire:click="chooseWantsAddons(true)"
                                    class="flex-1 cursor-pointer rounded-lg bg-rf-orange-600 py-2.5 text-sm font-semibold text-white transition hover:bg-rf-orange-700"
                                >
                                    Sim, quero adicionar
                                </button>
                            </div>
                        </div>
                    @else
                        @if ($this->availableAddons->isEmpty() && $this->availableGifts->isEmpty())
                            <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-500">Nenhum adicional disponível.</p>
                        @endif

                        @if ($this->availableGifts->isNotEmpty())
                            <div class="mb-3 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3">
                                <p class="mb-2 text-sm font-bold text-emerald-700 dark:text-emerald-300">🎁 Este item dá direito a brinde</p>
                                @foreach ($this->availableGifts as $gift)
                                    <label class="flex cursor-pointer items-center gap-2 py-1">
                                        <input type="checkbox" wire:click="toggleGift({{ $gift->id }})"
                                               @checked($giftSelections[$gift->id] ?? false)
                                               class="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-0 dark:border-white/20 dark:bg-transparent">
                                        <span class="text-sm text-gray-800 dark:text-gray-100">
                                            {{ $gift->pivot->quantity }}x {{ $gift->name }} <span class="font-semibold text-emerald-600 dark:text-emerald-400">grátis</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        @endif

                        @if ($this->availableAddons->isNotEmpty())
                        <p class="mb-2 text-xs text-gray-500 dark:text-gray-500">Deixe a quantidade em 0 nos adicionais que não quiser — eles não serão vinculados ao item.</p>
                        <ul class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($this->availableAddons as $addon)
                                @php
                                    $quantity = $selections[$addon->id]['quantity'] ?? 0;
                                    $target = $selections[$addon->id]['target'] ?? null;
                                    $flavorOptions = $this->flavorOptionsFor($addon);
                                    $allowsWhole = $this->allowsWholeProduct($addon);
                                @endphp
                                <li class="py-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $addon->name }}</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-500">{{ \Illuminate\Support\Number::currency((float) $addon->price, in: 'BRL', locale: 'pt_BR') }} / porção</p>
                                        </div>

                                        <div class="flex items-center gap-2">
                                            <button type="button" wire:click="setQuantity({{ $addon->id }}, {{ $quantity - 1 }})" @disabled($quantity === 0) class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 text-gray-600 hover:bg-gray-200 disabled:opacity-40 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-white/20">
                                                <x-heroicon-o-minus class="h-3 w-3" />
                                            </button>
                                            <span class="w-5 text-center text-sm text-gray-700 dark:text-gray-200">{{ $quantity }}</span>
                                            <button type="button" wire:click="setQuantity({{ $addon->id }}, {{ $quantity + 1 }})" class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-white/20">
                                                <x-heroicon-o-plus class="h-3 w-3" />
                                            </button>
                                        </div>
                                    </div>

                                    @if ($quantity > 0 && count($flavorIds) > 1)
                                        <div class="mt-2 flex flex-wrap gap-1.5 pl-1">
                                            @if ($allowsWhole)
                                                <button
                                                    type="button"
                                                    wire:click="setTarget({{ $addon->id }}, null)"
                                                    @class([
                                                        'rounded-full px-2.5 py-1 text-xs font-medium transition',
                                                        'bg-rf-orange-600 text-white' => $target === null,
                                                        'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => $target !== null,
                                                    ])
                                                >
                                                    Produto inteiro
                                                </button>
                                            @endif

                                            @foreach ($flavorOptions as $flavor)
                                                <button
                                                    type="button"
                                                    wire:click="setTarget({{ $addon->id }}, {{ $flavor->id }})"
                                                    @class([
                                                        'rounded-full px-2.5 py-1 text-xs font-medium transition',
                                                        'bg-rf-orange-600 text-white' => $target === $flavor->id,
                                                        'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => $target !== $flavor->id,
                                                    ])
                                                >
                                                    Só {{ $flavor->name }}
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @endif
                    @endif
                </div>

                @if ($wantsAddons === true)
                    <div class="flex gap-2 border-t border-gray-200 p-4 dark:border-white/10">
                        @if ($this->availableAddons->isNotEmpty())
                        <button
                            type="button"
                            wire:click="skipAddons"
                            class="flex-1 cursor-pointer rounded-lg border border-gray-200 bg-gray-50 py-2.5 text-sm font-semibold text-gray-600 transition hover:bg-gray-100 dark:border-white/10 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10"
                        >
                            {{ $editingIndex !== null ? 'Remover todos' : 'Prosseguir sem adicionais' }}
                        </button>
                        @endif
                        <button
                            type="button"
                            wire:click="confirmAddons"
                            class="flex-1 cursor-pointer rounded-lg bg-rf-orange-600 py-2.5 text-sm font-semibold text-white transition hover:bg-rf-orange-700"
                        >
                            {{ $editingIndex !== null ? 'Salvar adicionais' : 'Adicionar ao pedido' }}
                        </button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
