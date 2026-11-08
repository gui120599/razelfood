<div>
    @if ($open)
        @php
            $isCombo = $this->isCombo();
            $isEditing = $editingIndex !== null;
            $isSimpleItem = ! $isCombo && ! $isEditing;
            $product = $isSimpleItem ? $this->headerProduct : null;
            $preview = $this->preview;
            $hasAddons = $this->availableAddons->isNotEmpty();
            $hasGifts = $this->availableGifts->isNotEmpty();
        @endphp

        <div class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center" x-data wire:keydown.escape="cancel">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" wire:click="cancel"></div>

            <div class="relative w-full max-w-md bg-white dark:bg-gray-800 rounded-t-2xl sm:rounded-2xl shadow-2xl flex flex-col max-h-[92vh] z-10"
                 role="dialog" aria-modal="true" x-trap.noscroll="true">

                {{-- Cabeçalho: produto (item simples), "Quer adicionar algo?" (pizza) ou "Adicionais" (edição da linha) --}}
                <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-gray-100 dark:border-white/10 shrink-0">
                    @if ($product)
                        <div class="flex items-start gap-3 min-w-0">
                            @if ($product->image_url)
                                <img src="{{ $product->image_url }}" alt="{{ $product->name }}" width="56" height="56" class="w-14 h-14 object-cover rounded-lg shrink-0 bg-gray-100 dark:bg-gray-900">
                            @endif
                            <div class="min-w-0">
                                @if ($product->category)
                                    <span class="inline-block text-xs font-semibold uppercase tracking-wide text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-500/10 rounded px-1 mb-0.5">{{ $product->category->name }}</span>
                                @endif
                                <h3 class="text-gray-800 dark:text-gray-200 font-bold text-base leading-snug">{{ $product->name }}</h3>
                                <div class="flex items-baseline gap-2 mt-1">
                                    @if ($product->resolved_price < $product->resolved_original_price)
                                        <span class="text-xs text-gray-400 dark:text-gray-500 line-through">R$ {{ number_format($product->resolved_original_price, 2, ',', '.') }}</span>
                                        <span class="text-sm font-bold text-green-600 dark:text-green-400">R$ {{ number_format($product->resolved_price, 2, ',', '.') }}</span>
                                    @else
                                        <span class="text-sm font-bold text-gray-700 dark:text-gray-300">R$ {{ number_format($product->resolved_price, 2, ',', '.') }}</span>
                                    @endif
                                </div>
                                @if ($product->controls_stock)
                                    @php $balance = (float) $product->stock_quantity; @endphp
                                    <p class="inline-flex items-center gap-1 text-xs font-semibold mt-1 {{ $balance > 0 ? 'text-gray-500 dark:text-gray-400' : 'text-red-500 dark:text-red-400' }}">
                                        <x-heroicon-s-archive-box class="w-3 h-3" />
                                        Estoque: {{ $balance == floor($balance) ? (int) $balance : number_format($balance, 2, ',', '.') }} {{ $product->commercial_unit ?: 'UN' }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="min-w-0">
                            <h3 class="text-gray-800 dark:text-gray-200 font-bold text-base">{{ $isEditing ? 'Adicionais' : 'Quer adicionar algo?' }}</h3>
                            @if ($isCombo)
                                <p class="text-gray-500 dark:text-gray-400 text-sm mt-0.5">{{ $this->flavorNames }}</p>
                            @endif
                        </div>
                    @endif
                    <button wire:click="cancel" type="button" class="text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300 shrink-0 mt-0.5 transition-colors" aria-label="Fechar">
                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                    </button>
                </div>

                @if ($errorMessage)
                    <div class="mx-5 mt-3 rounded-lg bg-red-50 dark:bg-red-500/10 px-3 py-2 text-sm text-red-700 dark:text-red-400">{{ $errorMessage }}</div>
                @endif

                <div class="overflow-y-auto flex-1 px-5 py-4 space-y-5">

                    {{-- Quantidade (só item simples novo; pizza entra com 1 e ajusta no carrinho) --}}
                    @if ($isSimpleItem)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Quantidade</label>
                            <div class="flex items-center gap-3">
                                <button wire:click="decrementQuantity" type="button" aria-label="Diminuir"
                                    class="w-11 h-11 rounded-full bg-gray-100 hover:bg-red-50 dark:bg-white/5 dark:hover:bg-red-500/10 text-gray-700 dark:text-gray-300 font-bold text-xl flex items-center justify-center transition-colors">−</button>
                                <span class="min-w-[3.5rem] text-center text-2xl font-bold text-gray-800 dark:text-gray-200 tabular-nums">{{ $quantity }}</span>
                                <button wire:click="incrementQuantity" type="button" aria-label="Aumentar"
                                    class="w-11 h-11 rounded-full bg-gray-100 hover:bg-green-50 dark:bg-white/5 dark:hover:bg-green-500/10 text-gray-700 dark:text-gray-300 font-bold text-xl flex items-center justify-center transition-colors">+</button>
                            </div>
                        </div>
                    @endif

                    {{-- Brindes (RN-53): só o RazelFood tem --}}
                    @if ($hasGifts)
                        <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3">
                            <p class="mb-2 text-sm font-bold text-emerald-700 dark:text-emerald-300">🎁 Este item dá direito a brinde</p>
                            @foreach ($this->availableGifts as $gift)
                                <label class="flex cursor-pointer items-center gap-2 py-1" wire:key="gift-{{ $gift->id }}">
                                    <input type="checkbox" wire:click="toggleGift({{ $gift->id }})"
                                           @checked($giftSelections[$gift->id] ?? false)
                                           class="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-0 dark:border-white/20 dark:bg-transparent">
                                    <span class="text-sm text-gray-800 dark:text-gray-100">
                                        {{ $gift->pivot->quantity }}x {{ $gift->name }} <span class="font-semibold text-emerald-600 dark:text-emerald-400">grátis</span>
                                        @if (($gift->pivot->award_mode?->value ?? 'per_quantity') === 'per_order')
                                            <span class="text-xs text-emerald-600/80 dark:text-emerald-400/80">· 1 por pedido</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif

                    {{-- Adicionais: linha clicável (liga/desliga) e, ligado, quantidade e alvo por sabor --}}
                    @if ($hasAddons)
                        <div>
                            @if ($isSimpleItem)
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Quer adicionar algo?</label>
                            @endif
                            <div class="space-y-1.5">
                                @foreach ($this->availableAddons as $addon)
                                    @php
                                        $addonQuantity = $selections[$addon->id]['quantity'] ?? 0;
                                        $target = $selections[$addon->id]['target'] ?? null;
                                        $flavorOptions = $this->flavorOptionsFor($addon);
                                        $allowsWhole = $this->allowsWholeProduct($addon);
                                        $isOn = $addonQuantity > 0;
                                    @endphp
                                    <div wire:key="addon-{{ $addon->id }}"
                                         @class([
                                             'p-2.5 rounded-xl border transition-colors',
                                             'border-primary-400 dark:border-primary-500/50 bg-primary-50 dark:bg-primary-500/10' => $isOn,
                                             'border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900' => ! $isOn,
                                         ])>
                                        <div class="flex items-center justify-between gap-3">
                                            <button type="button" wire:click="setQuantity({{ $addon->id }}, {{ $isOn ? 0 : 1 }})"
                                                    aria-pressed="{{ $isOn ? 'true' : 'false' }}"
                                                    class="flex min-w-0 flex-1 items-center gap-2.5 text-left">
                                                <span @class([
                                                    'w-5 h-5 shrink-0 rounded border flex items-center justify-center text-xs text-white',
                                                    'border-primary-600 bg-primary-600' => $isOn,
                                                    'border-gray-300 dark:border-white/20' => ! $isOn,
                                                ])>@if ($isOn)✓@endif</span>
                                                <span class="truncate text-sm text-gray-700 dark:text-gray-300">{{ $addon->name }}</span>
                                            </button>

                                            @if ($isOn)
                                                <div class="flex items-center gap-2 shrink-0">
                                                    <button type="button" wire:click="setQuantity({{ $addon->id }}, {{ $addonQuantity - 1 }})" aria-label="Diminuir {{ $addon->name }}"
                                                        class="flex h-6 w-6 items-center justify-center rounded-full bg-white text-gray-600 hover:bg-gray-100 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-white/20">
                                                        <x-heroicon-o-minus class="h-3 w-3" />
                                                    </button>
                                                    <span class="w-5 text-center text-sm text-gray-700 dark:text-gray-200">{{ $addonQuantity }}</span>
                                                    <button type="button" wire:click="setQuantity({{ $addon->id }}, {{ $addonQuantity + 1 }})" aria-label="Aumentar {{ $addon->name }}" @disabled($this->maxQuantityOf($addon) !== null && $addonQuantity >= $this->maxQuantityOf($addon))
                                                        class="flex h-6 w-6 items-center justify-center rounded-full bg-white text-gray-600 hover:bg-gray-100 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-white/20">
                                                        <x-heroicon-o-plus class="h-3 w-3" />
                                                    </button>
                                                </div>
                                            @endif

                                            <span class="text-sm font-semibold text-green-600 dark:text-green-400 shrink-0">+ R$ {{ number_format($this->priceOf($addon), 2, ',', '.') }}</span>
                                        </div>

                                        @if ($isOn && count($flavorIds) > 1)
                                            <div class="mt-2 flex flex-wrap gap-1.5 pl-7">
                                                @if ($allowsWhole)
                                                    <button type="button" wire:click="setTarget({{ $addon->id }}, null)"
                                                        @class([
                                                            'rounded-full px-2.5 py-1 text-xs font-medium transition',
                                                            'bg-primary-600 text-white' => $target === null,
                                                            'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => $target !== null,
                                                        ])>Produto inteiro</button>
                                                @endif
                                                @foreach ($flavorOptions as $flavor)
                                                    <button type="button" wire:click="setTarget({{ $addon->id }}, {{ $flavor->id }})"
                                                        @class([
                                                            'rounded-full px-2.5 py-1 text-xs font-medium transition',
                                                            'bg-primary-600 text-white' => $target === $flavor->id,
                                                            'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => $target !== $flavor->id,
                                                        ])>Só {{ $flavor->name }}</button>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @elseif (! $hasGifts && ! $isSimpleItem)
                        <p class="py-6 text-center text-sm text-gray-500">Nenhum adicional disponível.</p>
                    @endif

                    {{-- Observação (item simples) --}}
                    @if ($isSimpleItem)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Observação</label>
                            <textarea wire:model="note" rows="2" maxlength="255" placeholder="Ex: sem cebola, bem passado..."
                                class="w-full border border-gray-300 dark:border-white/10 rounded-xl p-2.5 text-sm text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-900 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent resize-none"></textarea>
                        </div>
                    @endif

                    {{-- Total do item (simples) ou prévia (pizza): o servidor recalcula ao confirmar --}}
                    @if ($preview && ! $isEditing)
                        @if ($isSimpleItem)
                            <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-900/50 rounded-xl px-4 py-3">
                                <span class="text-sm text-gray-500 dark:text-gray-400">Total do item</span>
                                <span class="text-xl font-bold text-gray-800 dark:text-gray-200">R$ {{ number_format($preview['total'], 2, ',', '.') }}</span>
                            </div>
                        @else
                            <p class="text-sm text-gray-800 dark:text-gray-200">
                                Prévia: <span class="font-bold tabular-nums">R$ {{ number_format($preview['unit'] + $preview['addons'], 2, ',', '.') }}</span>
                                <span class="block text-xs text-gray-400 dark:text-gray-500">O valor final é calculado ao confirmar.</span>
                            </p>
                        @endif
                    @endif
                </div>

                {{-- Rodapé --}}
                <div class="flex gap-3 px-5 py-4 border-t border-gray-100 dark:border-white/10 shrink-0">
                    @if ($isEditing)
                        <button type="button" wire:click="skipAddons"
                            class="flex-1 min-h-11 border border-gray-300 dark:border-white/10 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">Remover todos</button>
                        <button type="button" wire:click="confirmAddons"
                            class="flex-1 min-h-11 bg-primary-600 hover:bg-primary-700 text-white rounded-xl text-sm font-bold transition-colors shadow-sm">Salvar adicionais</button>
                    @elseif ($isCombo)
                        <button type="button" wire:click="goBack"
                            class="min-h-11 px-4 border border-gray-300 dark:border-white/10 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">Voltar</button>
                        @if ($hasAddons)
                            <button type="button" wire:click="skipAddons"
                                class="flex-1 min-h-11 border border-gray-300 dark:border-white/10 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">Sem adicionais</button>
                        @endif
                        <button type="button" wire:click="confirmAddons"
                            class="flex-1 min-h-11 bg-primary-600 hover:bg-primary-700 text-white rounded-xl text-sm font-bold transition-colors shadow-sm">Adicionar</button>
                    @else
                        <button type="button" wire:click="{{ $canGoBack ? 'goBack' : 'cancel' }}"
                            class="flex-1 min-h-11 border border-gray-300 dark:border-white/10 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">{{ $canGoBack ? 'Voltar' : 'Cancelar' }}</button>
                        <button type="button" wire:click="confirmAddons"
                            class="flex-1 min-h-11 bg-primary-600 hover:bg-primary-700 text-white rounded-xl text-sm font-bold transition-colors shadow-sm">Adicionar ao Pedido</button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
