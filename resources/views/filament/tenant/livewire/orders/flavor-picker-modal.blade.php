<div>
    @if ($open)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/70 backdrop-blur-sm sm:items-center" x-data>
            <div
                class="flex max-h-[85vh] w-full flex-col overflow-hidden rounded-t-2xl border border-gray-200 bg-white sm:max-w-lg sm:rounded-2xl dark:border-white/10 dark:bg-gray-900"
                x-trap.noscroll="true"
            >
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <h3 class="font-heading text-base font-semibold text-gray-900 dark:text-white">{{ $this->currentCategory?->name }}</h3>
                    <button type="button" wire:click="closeModal" class="text-gray-400 hover:text-gray-700 dark:hover:text-white">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                @if ($this->currentCategory?->resolvedFlavorQuantityOptions()->count() > 1)
                    <div class="flex gap-2 border-b border-gray-200 px-4 py-3 dark:border-white/10">
                        @foreach ($this->currentCategory->resolvedFlavorQuantityOptions() as $option)
                            <button
                                type="button"
                                wire:click="selectFlavorQuantity({{ $option->id }})"
                                @class([
                                    'flex-1 rounded-lg px-3 py-2 text-xs font-semibold uppercase tracking-wide transition',
                                    'bg-rf-orange-600 text-white' => $comboBuilder['quantity_option_id'] === $option->id,
                                    'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => $comboBuilder['quantity_option_id'] !== $option->id,
                                ])
                            >
                                {{ $option->label }}
                            </button>
                        @endforeach
                    </div>
                @endif

                @if ($errorMessage)
                    <div class="mx-4 mt-3 rounded-lg bg-rf-danger/10 px-3 py-2 text-sm text-rf-danger">
                        {{ $errorMessage }}
                    </div>
                @endif

                <div class="flex-1 overflow-y-auto px-4 py-3">
                    <ul class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($this->availableFlavors as $flavor)
                            @php
                                $position = array_search($flavor->id, $comboBuilder['flavor_ids'], true);
                                $selected = $position !== false;
                            @endphp
                            <li>
                                <button
                                    type="button"
                                    wire:click="toggleFlavor({{ $flavor->id }})"
                                    class="flex w-full items-center justify-between gap-3 py-2.5 text-left"
                                >
                                    <span class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                                        @if ($selected && $comboBuilder['required_count'] > 1)
                                            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-rf-orange-600 text-[10px] font-bold text-white">
                                                {{ $position + 1 }}º
                                            </span>
                                        @endif
                                        {{ $flavor->name }}
                                    </span>

                                    <span @class([
                                        'flex h-6 w-6 shrink-0 items-center justify-center rounded-full border transition',
                                        'border-rf-orange-600 bg-rf-orange-600 text-white' => $selected,
                                        'border-gray-300 text-transparent dark:border-white/20' => ! $selected,
                                    ])>
                                        <x-heroicon-o-check class="h-4 w-4" />
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="border-t border-gray-200 p-4 dark:border-white/10">
                    <button
                        type="button"
                        wire:click="confirmCombo"
                        @disabled(count($comboBuilder['flavor_ids']) !== $comboBuilder['required_count'])
                        class="w-full rounded-lg bg-rf-orange-600 py-2.5 text-sm font-semibold text-white transition hover:bg-rf-orange-700 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        Adicionar ao pedido
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
