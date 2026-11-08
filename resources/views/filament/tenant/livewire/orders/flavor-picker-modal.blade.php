<div>
    {{-- Desenho da pizza (copiado da origem); fica fora do @if porque @assets precisa estar no topo do componente. --}}
    @assets
        <script src="{{ asset('js/app/pizza-svg.js') }}"></script>
    @endassets

    @if ($open && $this->currentCategory)
        @php
            $required = (int) $comboBuilder['required_count'];
            $chosenCount = count($comboBuilder['flavor_ids']);
            $ready = $required > 0 && $chosenCount === $required;
            $options = $this->currentCategory->resolvedFlavorQuantityOptions();
            $activeOption = $options->firstWhere('id', $comboBuilder['quantity_option_id']);
            $drawing = [
                'quantidade' => max(1, $required),
                'sabores' => $this->chosenFlavors->map(fn ($flavor) => ['foto' => $flavor->image_url, 'nome' => $flavor->name])->all(),
            ];
        @endphp

        <div class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center" x-data wire:keydown.escape="closeModal">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" wire:click="closeModal"></div>

            <div class="relative w-full max-w-lg bg-white dark:bg-gray-800 rounded-t-2xl sm:rounded-2xl shadow-2xl flex flex-col max-h-[92vh] z-10"
                 role="dialog" aria-modal="true" x-trap.noscroll="true">

                {{-- Header --}}
                <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-white/10 shrink-0">
                    <div>
                        <h3 class="text-gray-800 dark:text-gray-200 font-bold text-base">{{ $this->currentCategory->name }}</h3>
                        <p class="text-gray-500 dark:text-gray-400 text-sm mt-0.5">
                            <span class="font-semibold {{ $ready ? 'text-green-600 dark:text-green-400' : 'text-primary-600 dark:text-primary-400' }}">{{ $chosenCount }} de {{ $required }}</span>
                            {{ $required > 1 ? 'sabores escolhidos' : 'sabor escolhido' }}
                        </p>
                    </div>
                    <button wire:click="closeModal" type="button" class="w-11 h-11 -mr-2 flex items-center justify-center rounded-full text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300 transition-colors" aria-label="Fechar">
                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                    </button>
                </div>

                {{-- Seletor de modo: os rótulos são os cadastrados na categoria (flavor_quantity_options) --}}
                <div class="px-5 py-3 border-b border-gray-100 dark:border-white/10 shrink-0">
                    <p class="text-gray-500 dark:text-gray-400 text-xs uppercase font-semibold tracking-wide mb-2">Como deseja?</p>
                    <div class="flex gap-2">
                        @foreach ($options as $option)
                            <button wire:click="selectFlavorQuantity({{ $option->id }})" type="button" wire:key="flavor-option-{{ $option->id }}"
                                @class([
                                    'flex-1 min-h-11 border rounded-lg py-2 text-sm font-semibold transition-colors',
                                    'bg-primary-600 text-white border-primary-600' => $comboBuilder['quantity_option_id'] === $option->id,
                                    'bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-300 border-gray-300 dark:border-white/10 hover:border-primary-400' => $comboBuilder['quantity_option_id'] !== $option->id,
                                ])>
                                {{ $option->label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Desenho da pizza e a prévia de preço --}}
                <div class="flex items-center gap-4 px-5 py-3 border-b border-gray-100 dark:border-white/10 shrink-0">
                    <div class="w-24 h-24 shrink-0"
                         wire:key="pizza-drawing-{{ $required }}-{{ implode('-', $comboBuilder['flavor_ids']) }}"
                         x-data
                         x-html="window.desenharPizza ? window.desenharPizza(@js($drawing)) : ''"></div>
                    <div class="min-w-0 text-sm">
                        @if ($required > 1 && $activeOption)
                            <p class="text-gray-500 dark:text-gray-400">{{ $activeOption->label }}: valor pela média dos sabores</p>
                        @endif
                        @if ($this->previewPrice !== null)
                            <p class="mt-1 text-gray-800 dark:text-gray-200">
                                Prévia: <span class="font-bold tabular-nums">R$ {{ number_format($this->previewPrice, 2, ',', '.') }}</span>
                                <span class="block text-xs text-gray-400 dark:text-gray-500">O valor final é calculado ao confirmar.</span>
                            </p>
                        @endif
                    </div>
                </div>

                @if ($errorMessage)
                    <div class="mx-5 mt-3 rounded-lg bg-red-50 dark:bg-red-500/10 px-3 py-2 text-sm text-red-700 dark:text-red-400">{{ $errorMessage }}</div>
                @endif

                {{-- Lista de sabores --}}
                <div class="overflow-y-auto flex-1 px-4 py-2">
                    @foreach ($this->availableFlavors as $flavor)
                        @php
                            $position = array_search($flavor->id, $comboBuilder['flavor_ids'], true);
                            $selected = $position !== false;
                            $hasPromo = $flavor->resolved_price < $flavor->resolved_original_price;
                        @endphp
                        <div wire:key="flavor-{{ $flavor->id }}"
                             wire:click="toggleFlavor({{ $flavor->id }})"
                             @class([
                                 'flex items-center gap-3 p-3 mb-2 rounded-xl border-2 cursor-pointer transition-colors select-none',
                                 'border-primary-500 bg-primary-50 dark:border-primary-500/60 dark:bg-primary-500/10' => $selected,
                                 'border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 hover:border-primary-300' => ! $selected,
                             ])>
                            @if ($flavor->image_url)
                                <img src="{{ $flavor->image_url }}" alt="{{ $flavor->name }}" width="56" height="56" loading="lazy" class="w-14 h-14 object-cover rounded-lg shrink-0 bg-gray-100 dark:bg-gray-900">
                            @else
                                <div class="w-14 h-14 rounded-lg bg-gray-100 dark:bg-gray-900 flex items-center justify-center shrink-0">
                                    <x-heroicon-o-photo class="w-6 h-6 text-gray-400 dark:text-gray-500" />
                                </div>
                            @endif
                            <div class="flex-1 min-w-0">
                                <p class="text-gray-800 dark:text-gray-200 text-sm font-semibold leading-tight">{{ $flavor->name }}</p>
                                @if ($flavor->description)
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 line-clamp-2">{{ $flavor->description }}</p>
                                @endif
                                <div class="flex items-baseline gap-1.5 mt-0.5">
                                    @if ($hasPromo)
                                        <span class="text-gray-400 dark:text-gray-500 text-xs line-through">R$ {{ number_format($flavor->resolved_original_price, 2, ',', '.') }}</span>
                                        <span class="text-green-600 dark:text-green-400 text-sm font-bold">R$ {{ number_format($flavor->resolved_price, 2, ',', '.') }}</span>
                                    @else
                                        <span class="text-gray-700 dark:text-gray-300 text-sm font-bold">R$ {{ number_format($flavor->resolved_price, 2, ',', '.') }}</span>
                                    @endif
                                </div>
                                @if ($flavor->controls_stock)
                                    @php $balance = (float) $flavor->stock_quantity; @endphp
                                    <span class="inline-flex items-center gap-0.5 text-xs font-semibold mt-0.5 {{ $balance > 0 ? 'text-gray-500 dark:text-gray-400' : 'text-red-500 dark:text-red-400' }}">
                                        <x-heroicon-s-archive-box class="w-3 h-3" />
                                        {{ $balance == floor($balance) ? (int) $balance : number_format($balance, 2, ',', '.') }} {{ $flavor->commercial_unit ?: 'UN' }}
                                    </span>
                                @endif
                            </div>
                            <div @class([
                                    'w-8 h-8 rounded-full flex items-center justify-center shrink-0 transition-colors text-white text-sm font-bold',
                                    'bg-primary-600' => $selected,
                                    'bg-gray-200 dark:bg-gray-700' => ! $selected,
                                 ])>
                                @if ($selected){{ $required > 1 ? ($position + 1).'º' : '✓' }}@endif
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Rodapé --}}
                <div class="px-5 py-4 border-t border-gray-100 dark:border-white/10 shrink-0 space-y-3">
                    @if ($chosenCount > 0)
                        <p class="text-gray-500 dark:text-gray-400 text-xs">
                            <span class="font-semibold text-gray-700 dark:text-gray-300">Selecionados:</span>
                            {{ $this->chosenFlavors->pluck('name')->implode(' / ') }}
                        </p>
                    @endif

                    <input type="text" wire:model="note" maxlength="255"
                        placeholder="Observação (ex.: sem cebola, bem assada)"
                        class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-xl bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent" />

                    <div class="flex gap-3">
                        <button wire:click="closeModal" type="button"
                            class="flex-1 min-h-11 py-2.5 border border-gray-300 dark:border-white/10 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">
                            Cancelar
                        </button>
                        <button wire:click="confirmCombo" type="button" @disabled(! $ready)
                            @class([
                                'flex-1 min-h-11 py-2.5 rounded-xl text-sm font-bold transition-colors shadow-sm',
                                'bg-primary-600 hover:bg-primary-700 text-white' => $ready,
                                'bg-gray-100 dark:bg-white/5 text-gray-400 dark:text-gray-500 cursor-not-allowed' => ! $ready,
                            ])>
                            {{ $ready ? ($this->hasExtras ? 'Continuar' : 'Confirmar') : 'Selecione '.max(0, $required - $chosenCount).' mais' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
