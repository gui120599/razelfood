<div class="space-y-6 pb-28">
    {{-- Aberto/fechado (RN-23) --}}
    <div @class([
        'flex items-center gap-2 rounded-xl border px-3 py-2 text-sm font-medium',
        'border-green-500/30 bg-green-500/10 text-green-400' => $this->businessHours->isOpen,
        'border-red-500/30 bg-red-500/10 text-red-400' => ! $this->businessHours->isOpen,
    ])>
        <x-heroicon-o-clock class="h-4 w-4 shrink-0" />
        @if ($this->businessHours->isOpen)
            Estamos abertos!
        @else
            Estamos fechados. {{ $this->businessHours->message }}
        @endif
    </div>

    {{-- Busca de produtos (RF-10) --}}
    <div class="relative">
        <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-500" />
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Buscar no cardápio…"
               class="w-full rounded-xl border border-white/15 bg-white/5 py-2.5 pl-10 pr-10 text-sm text-white placeholder-gray-500 focus:border-[var(--tenant-primary)] focus:outline-none">
        @if ($this->isSearching())
            <button type="button" wire:click="$set('search', '')"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        @endif
    </div>

    @if ($this->isSearching())
        {{-- Resultados da busca --}}
        <section>
            @if ($this->searchResults->isEmpty())
                <p class="rounded-xl border border-white/10 bg-white/5 px-3 py-8 text-center text-sm text-gray-400">
                    Nenhum produto encontrado para "{{ trim($search) }}".
                </p>
            @else
                <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-400">
                    {{ $this->searchResults->count() }} resultado(s)
                </h2>
                @foreach ($this->searchResults as $product)
                    <x-menu.product-card wire:key="search-{{ $product->id }}" :product="$product" :category="$product->category" />
                @endforeach
            @endif
        </section>
    @else

    {{-- Navegação rápida por categoria --}}
    @if ($this->categories->isNotEmpty())
        <div class="-mx-2 flex gap-2 overflow-x-auto px-2 pb-1">
            @if ($this->activePromotions->isNotEmpty())
                <a href="#secao-relampago" class="shrink-0 rounded-full border border-red-500/40 bg-red-500/10 px-3 py-1 text-xs font-semibold text-red-400 whitespace-nowrap">
                    ⚡ Relâmpago
                </a>
            @endif
            @if ($this->bestsellers->isNotEmpty())
                <a href="#secao-mais-vendidos" class="shrink-0 rounded-full border border-yellow-500/40 bg-yellow-500/10 px-3 py-1 text-xs font-semibold text-yellow-400 whitespace-nowrap">
                    ⭐ Mais vendidos
                </a>
            @endif
            @foreach ($this->categories as $category)
                <a href="#categoria-{{ $category->id }}" class="flex shrink-0 items-center gap-1.5 rounded-full border border-white/15 bg-white/5 py-1 pl-1 pr-3 text-xs font-semibold text-gray-300 whitespace-nowrap">
                    @if ($category->nav_thumbnail_url)
                        <img src="{{ $category->nav_thumbnail_url }}" alt="" class="h-6 w-6 shrink-0 rounded-full bg-white object-cover">
                    @else
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-700 text-gray-500">
                            <x-heroicon-o-photo class="h-3.5 w-3.5" />
                        </span>
                    @endif
                    {{ $category->name }}
                </a>
            @endforeach
        </div>
    @endif

    {{-- Promoções relâmpago ativas --}}
    @if ($this->activePromotions->isNotEmpty())
        <div class="space-y-4">
            @foreach ($this->activePromotions as $promotion)
                <section wire:key="promo-{{ $promotion->id }}" id="{{ $loop->first ? 'secao-relampago' : 'secao-relampago-'.$promotion->id }}" class="scroll-mt-16">
                    <div class="flex items-center justify-between gap-2 rounded-t-xl bg-gradient-to-r from-red-600 to-orange-500 px-3 py-2">
                        <div class="flex min-w-0 items-center gap-2">
                            <x-heroicon-s-bolt class="h-6 w-6 shrink-0 animate-pulse text-yellow-300" />
                            <div class="min-w-0">
                                <p class="truncate text-base font-extrabold uppercase leading-tight text-white">{{ $promotion->name }}</p>
                                @if ($promotion->description)
                                    <p class="truncate text-[11px] leading-tight text-white/80">{{ $promotion->description }}</p>
                                @endif
                            </div>
                        </div>
                        @if ($promotion->show_counter && $promotion->total_quantity !== null)
                            @php $remaining = $promotion->total_quantity - $promotion->sold_quantity; @endphp
                            @if ($promotion->scarcity_threshold === null || $remaining <= $promotion->scarcity_threshold)
                                <div class="shrink-0 rounded-lg bg-white/95 px-2 py-1 text-center shadow">
                                    <span class="block text-lg font-extrabold leading-none text-red-600">{{ number_format($remaining, 0, ',', '.') }}</span>
                                    <span class="block text-[9px] font-bold uppercase tracking-wide text-red-600">restam</span>
                                </div>
                            @endif
                        @endif
                    </div>
                    <div class="rounded-b-xl border border-t-0 border-red-500/40 bg-red-500/5 p-2">
                        @foreach ($promotion->products as $product)
                            <x-menu.product-card wire:key="promo-product-{{ $promotion->id }}-{{ $product->id }}" :product="$product" :category="$product->category" />
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif

    {{-- Mais vendidos --}}
    @if ($this->bestsellers->isNotEmpty())
        <section id="secao-mais-vendidos" class="scroll-mt-16">
            <h2 class="mb-2 flex items-center gap-1.5 text-lg font-bold uppercase text-yellow-400">
                <x-heroicon-s-star class="h-5 w-5" /> Mais vendidos
            </h2>
            <div>
                @foreach ($this->bestsellers as $product)
                    <x-menu.product-card wire:key="bestseller-{{ $product->id }}" :product="$product" :category="$product->category" badge="bestseller" />
                @endforeach
            </div>
        </section>
    @endif

    {{-- Categorias --}}
    @foreach ($this->categories as $category)
        <section wire:key="category-{{ $category->id }}" id="categoria-{{ $category->id }}" class="scroll-mt-16" x-data="{ activeChild: 'all' }">
            <h2 class="sticky top-14 z-20 -mx-2 bg-black px-2 py-2 text-lg font-bold text-white">{{ $category->name }}</h2>

            @if ($category->allows_flavors && $category->flavorQuantityOptions->isNotEmpty())
                <button wire:click="startCombo({{ $category->id }})"
                        class="mb-3 rounded-md border border-[var(--tenant-primary)] px-3 py-1 text-sm text-[var(--tenant-primary)]">
                    Montar combo de sabores
                </button>
            @endif

            @if ($category->children->count() > 1)
                <div class="-mx-2 mb-2 flex gap-1 overflow-x-auto px-2 pb-1">
                    <button type="button" @click="activeChild = 'all'"
                            :class="activeChild === 'all' ? 'bg-[var(--tenant-primary)] text-white border-transparent' : 'bg-gray-800 text-gray-300 border-gray-700'"
                            class="shrink-0 whitespace-nowrap rounded-full border px-2.5 py-0.5 text-[11px] font-semibold transition">
                        Todos
                    </button>
                    @foreach ($category->children as $child)
                        <button type="button" @click="activeChild = '{{ $child->id }}'"
                                :class="activeChild === '{{ $child->id }}' ? 'bg-[var(--tenant-primary)] text-white border-transparent' : 'bg-gray-800 text-gray-300 border-gray-700'"
                                class="shrink-0 whitespace-nowrap rounded-full border px-2.5 py-0.5 text-[11px] font-semibold transition">
                            {{ $child->name }}
                        </button>
                    @endforeach
                </div>
            @endif

            <div x-show="activeChild === 'all'">
                @foreach ($category->products as $product)
                    <x-menu.product-card wire:key="product-{{ $product->id }}" :product="$product" :category="$category" />
                @endforeach
            </div>

            @foreach ($category->children as $child)
                <div x-show="activeChild === 'all' || activeChild === '{{ $child->id }}'" wire:key="subcategory-{{ $child->id }}" class="mt-1">
                    <h3 class="mb-1 border-l-4 border-[var(--tenant-primary)]/60 pl-2 text-base font-bold uppercase tracking-wide text-gray-300">{{ $child->name }}</h3>
                    @foreach ($child->products as $product)
                        <x-menu.product-card wire:key="product-{{ $product->id }}" :product="$product" :category="$child" />
                    @endforeach
                </div>
            @endforeach
        </section>
    @endforeach
    @endif {{-- fim @else de isSearching --}}

    {{-- Visualização rápida do produto --}}
    @if ($this->viewingProduct)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/70 sm:items-center" wire:click.self="closeProductView">
            <div class="max-h-[90vh] w-full overflow-y-auto rounded-t-3xl bg-gray-900 sm:max-w-md sm:rounded-3xl">
                <div class="relative">
                    @if ($this->viewingProduct->image_url)
                        <img src="{{ $this->viewingProduct->image_url }}" alt="{{ $this->viewingProduct->name }}"
                             class="h-56 w-full rounded-t-3xl bg-white object-cover sm:rounded-t-3xl">
                    @else
                        <div class="flex h-56 w-full items-center justify-center rounded-t-3xl bg-gray-800 text-gray-600">
                            <x-heroicon-o-photo class="h-12 w-12" />
                        </div>
                    @endif
                    <button wire:click="closeProductView"
                            class="absolute right-3 top-3 flex h-9 w-9 items-center justify-center rounded-full bg-black/60 text-white">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="space-y-3 p-4">
                    <h3 class="text-xl font-bold uppercase text-white">{{ $this->viewingProduct->name }}</h3>

                    @if ($this->viewingProduct->description)
                        <p class="text-sm text-gray-400">{{ $this->viewingProduct->description }}</p>
                    @endif

                    @php $onPromo = (float) $this->viewingProduct->resolved_price < (float) $this->viewingProduct->resolved_original_price; @endphp
                    <div>
                        @if ($onPromo)
                            <p class="text-sm text-gray-500 line-through">De R$ {{ number_format($this->viewingProduct->resolved_original_price, 2, ',', '.') }}</p>
                            <p class="text-2xl font-bold text-green-400">R$ {{ number_format($this->viewingProduct->resolved_price, 2, ',', '.') }}</p>
                        @else
                            <p class="text-2xl font-bold text-white">R$ {{ number_format($this->viewingProduct->resolved_price, 2, ',', '.') }}</p>
                        @endif
                    </div>

                    @if ($this->viewingProduct->category?->allows_flavors && $this->viewingProduct->category->flavorQuantityOptions->isNotEmpty())
                        <button wire:click="startCombo({{ $this->viewingProduct->category->id }}, {{ $this->viewingProduct->id }})"
                                class="flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--tenant-primary)] py-3 text-sm font-bold uppercase tracking-wide text-white">
                            Escolher quantidade de sabores
                        </button>
                    @else
                        @if ($this->viewingProductAddons->isNotEmpty())
                            <div class="space-y-2 border-t border-white/10 pt-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Adicionais</p>
                                <p class="text-xs text-gray-500">Deixe a quantidade em 0 nos adicionais que não quiser — eles não serão vinculados ao pedido.</p>
                                @foreach ($this->viewingProductAddons as $addon)
                                    @php $quantity = $addonSelections[$addon->id]['quantity'] ?? 0; @endphp
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm text-gray-200">{{ $addon->name }}</p>
                                            <p class="text-xs text-gray-500">R$ {{ number_format((float) $addon->price, 2, ',', '.') }} / porção</p>
                                        </div>
                                        <div class="flex shrink-0 items-center gap-2">
                                            <button type="button" wire:click="setAddonQuantity({{ $addon->id }}, {{ $quantity - 1 }})" @disabled($quantity === 0)
                                                    class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-700 font-bold text-white transition hover:bg-gray-600 disabled:opacity-40">−</button>
                                            <span class="w-5 text-center text-sm font-bold text-white">{{ $quantity }}</span>
                                            <button type="button" wire:click="setAddonQuantity({{ $addon->id }}, {{ $quantity + 1 }})"
                                                    class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-700 font-bold text-white transition hover:bg-gray-600">+</button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <button wire:click="addFromView"
                                class="flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--tenant-primary)] py-3 text-sm font-bold uppercase tracking-wide text-white">
                            <x-heroicon-o-shopping-cart class="h-5 w-5" /> Adicionar ao pedido
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Montador de combo — modal único: quantidade + lista de sabores --}}
    @if ($comboBuilder['category_id'])
        @php
            $comboCategory = $this->categories->firstWhere('id', $comboBuilder['category_id']);

            // Produtos em promoção "só inteira" não entram em combos de 2+
            // sabores — só ficam de fora quando a quantidade exige mais de 1.
            $selectableFlavors = $comboBuilder['required_count'] === 1
                ? ($comboCategory?->products ?? collect())
                : ($comboCategory?->products ?? collect())->reject(fn ($product) => $product->resolved_flavor_combo_blocked ?? false);
        @endphp
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/70 sm:items-center" wire:click.self="cancelCombo">
            <div class="flex max-h-[90vh] w-full flex-col rounded-t-3xl bg-gray-900 sm:max-w-md sm:rounded-3xl">
                <div class="flex items-center justify-between border-b border-white/10 px-4 py-3">
                    <div>
                        <h3 class="text-base font-bold text-white">{{ $comboCategory?->name }}</h3>
                        <p class="text-xs text-gray-400">Escolha a quantidade e os sabores</p>
                    </div>
                    <button wire:click="cancelCombo" class="text-gray-400"><x-heroicon-o-x-mark class="h-6 w-6" /></button>
                </div>

                @if (($comboCategory?->flavorQuantityOptions ?? collect())->isEmpty())
                    <p class="px-4 py-6 text-center text-sm text-gray-500">Nenhuma opção de sabores cadastrada para esta categoria.</p>
                @elseif ($comboBuilder['step'] === 'addons' && $comboAddonsGate === null)
                    {{-- Pergunta antes de mostrar a lista — mesmo sub-passo, sem virar modal novo --}}
                    <div class="flex flex-1 flex-col items-center justify-center gap-4 px-4 py-8 text-center">
                        <p class="text-sm text-gray-300">Deseja adicionar algum adicional a este combo?</p>
                        <div class="flex w-full gap-2">
                            <button type="button" wire:click="chooseComboWantsAddons(false)"
                                    class="flex-1 rounded-xl border border-white/10 bg-white/5 py-3 text-sm font-bold text-gray-300 transition hover:bg-white/10">
                                Não, pular
                            </button>
                            <button type="button" wire:click="chooseComboWantsAddons(true)"
                                    class="flex-1 rounded-xl bg-[var(--tenant-primary)] py-3 text-sm font-bold text-white">
                                Sim, quero adicionar
                            </button>
                        </div>
                    </div>
                @elseif ($comboBuilder['step'] === 'addons')
                    {{-- Sub-passo de adicionais (RN-48) — mesmo modal, nunca um novo --}}
                    <div class="flex-1 space-y-3 overflow-y-auto px-4 py-3">
                        @if ($this->comboAddons->isNotEmpty())
                            <p class="text-xs text-gray-500">Deixe a quantidade em 0 nos adicionais que não quiser — eles não serão vinculados ao pedido.</p>
                        @endif
                        @forelse ($this->comboAddons as $addon)
                            @php
                                $quantity = $addonSelections[$addon->id]['quantity'] ?? 0;
                                $target = $addonSelections[$addon->id]['target'] ?? null;
                                $flavorOptions = $this->comboFlavorOptionsFor($addon);
                                $allowsWhole = $this->comboAllowsWholeProduct($addon);
                            @endphp
                            <div wire:key="combo-addon-{{ $addon->id }}" class="rounded-xl border border-white/10 bg-white/5 p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-gray-100">{{ $addon->name }}</p>
                                        <p class="text-xs text-gray-500">R$ {{ number_format((float) $addon->price, 2, ',', '.') }} / porção</p>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <button type="button" wire:click="setAddonQuantity({{ $addon->id }}, {{ $quantity - 1 }})" @disabled($quantity === 0)
                                                class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-700 font-bold text-white transition hover:bg-gray-600 disabled:opacity-40">−</button>
                                        <span class="w-5 text-center text-sm font-bold text-white">{{ $quantity }}</span>
                                        <button type="button" wire:click="setAddonQuantity({{ $addon->id }}, {{ $quantity + 1 }})"
                                                class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-700 font-bold text-white transition hover:bg-gray-600">+</button>
                                    </div>
                                </div>

                                @if ($quantity > 0 && count($comboBuilder['flavor_ids']) > 1)
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        @if ($allowsWhole)
                                            <button type="button" wire:click="setAddonTarget({{ $addon->id }}, null)"
                                                    @class([
                                                        'rounded-full px-2.5 py-1 text-xs font-medium transition',
                                                        'bg-[var(--tenant-primary)] text-white' => $target === null,
                                                        'bg-white/10 text-gray-300' => $target !== null,
                                                    ])>
                                                Produto inteiro
                                            </button>
                                        @endif
                                        @foreach ($flavorOptions as $flavor)
                                            <button type="button" wire:click="setAddonTarget({{ $addon->id }}, {{ $flavor->id }})"
                                                    @class([
                                                        'rounded-full px-2.5 py-1 text-xs font-medium transition',
                                                        'bg-[var(--tenant-primary)] text-white' => $target === $flavor->id,
                                                        'bg-white/10 text-gray-300' => $target !== $flavor->id,
                                                    ])>
                                                Só {{ $flavor->name }}
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @empty
                            <p class="py-6 text-center text-sm text-gray-500">Nenhum adicional disponível.</p>
                        @endforelse
                    </div>

                    <div class="shrink-0 flex gap-2 border-t border-white/10 p-4">
                        <button wire:click="skipComboAddons"
                                class="flex-1 rounded-xl border border-white/10 bg-white/5 py-3 text-sm font-bold text-gray-300 transition hover:bg-white/10">
                            Prosseguir sem adicionais
                        </button>
                        <button wire:click="confirmComboAddons"
                                class="flex-1 rounded-xl bg-[var(--tenant-primary)] py-3 text-sm font-bold text-white">
                            Adicionar ao carrinho
                        </button>
                    </div>
                @else
                    {{-- Quantidade de sabores --}}
                    <div class="shrink-0 border-b border-white/10 px-4 py-3">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Como deseja?</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($comboCategory->flavorQuantityOptions as $option)
                                <button type="button" wire:key="quantity-{{ $option->id }}"
                                        wire:click="selectFlavorQuantity({{ $option->id }})"
                                        @class([
                                            'flex-1 rounded-lg border px-3 py-2 text-center text-sm font-semibold transition',
                                            'border-[var(--tenant-primary)] bg-[var(--tenant-primary)]/10 text-[var(--tenant-primary)]' => $comboBuilder['quantity_option_id'] === $option->id,
                                            'border-white/10 bg-white/5 text-gray-300' => $comboBuilder['quantity_option_id'] !== $option->id,
                                        ])>
                                    {{ $option->label }}
                                </button>
                            @endforeach
                        </div>
                        <p class="mt-1.5 text-center text-xs text-gray-500">
                            Selecione {{ $comboBuilder['required_count'] }} {{ \Illuminate\Support\Str::plural('sabor', $comboBuilder['required_count']) }}
                            @if (count($comboBuilder['flavor_ids']) > 0)
                                <span class="text-gray-400">— {{ count($comboBuilder['flavor_ids']) }}/{{ $comboBuilder['required_count'] }} escolhido{{ count($comboBuilder['flavor_ids']) > 1 ? 's' : '' }}</span>
                            @endif
                        </p>
                    </div>

                    {{-- Lista de sabores — o produto clicado no cardápio já vem marcado --}}
                    <div class="flex-1 space-y-2 overflow-y-auto px-4 py-3">
                        @foreach ($selectableFlavors as $product)
                            <label wire:key="flavor-{{ $product->id }}"
                                   @class([
                                       'flex items-center gap-3 rounded-xl border p-2 text-sm transition cursor-pointer',
                                       'border-[var(--tenant-primary)] bg-[var(--tenant-primary)]/10' => in_array($product->id, $comboBuilder['flavor_ids'], true),
                                       'border-white/10 bg-white/5' => ! in_array($product->id, $comboBuilder['flavor_ids'], true),
                                   ])>
                                <input type="checkbox" class="sr-only"
                                       wire:click="toggleFlavor({{ $product->id }})"
                                       @checked(in_array($product->id, $comboBuilder['flavor_ids'], true))>
                                @if ($product->image_url)
                                    <img src="{{ $product->image_url }}" alt="{{ $product->name }}" class="h-12 w-12 shrink-0 rounded-lg bg-white object-cover">
                                @endif
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-semibold uppercase text-gray-100">{{ $product->name }}</p>
                                    <p class="text-xs font-bold text-green-400">R$ {{ number_format($product->resolved_price, 2, ',', '.') }}</p>
                                </div>
                                @if (in_array($product->id, $comboBuilder['flavor_ids'], true))
                                    <x-heroicon-s-check-circle class="h-5 w-5 shrink-0 text-[var(--tenant-primary)]" />
                                @endif
                            </label>
                        @endforeach
                    </div>

                    <div class="shrink-0 space-y-2 border-t border-white/10 p-4">
                        @if ($this->comboPreview)
                            <div>
                                <p class="truncate text-sm font-medium text-gray-200">{{ $this->comboPreview['names'] }}</p>
                                <p class="text-base font-bold text-green-400">R$ {{ number_format($this->comboPreview['unit_price'], 2, ',', '.') }}</p>
                            </div>
                        @endif
                        <button wire:click="confirmCombo"
                                @disabled(count($comboBuilder['flavor_ids']) !== $comboBuilder['required_count'])
                                class="w-full rounded-xl bg-[var(--tenant-primary)] py-3 text-sm font-bold text-white disabled:cursor-not-allowed disabled:bg-gray-700 disabled:text-gray-500">
                            Adicionar ao carrinho
                        </button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Botão flutuante do carrinho --}}
    @if ($this->cartItemCount > 0)
        <div class="fixed inset-x-0 bottom-4 z-40 flex justify-center px-4">
            <button wire:click="$set('showCart', true)"
                    class="flex w-full max-w-sm items-center justify-between rounded-2xl bg-[var(--tenant-primary)] px-4 py-3 font-bold text-white shadow-xl transition active:scale-[0.98]">
                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-black/25 text-sm font-bold">{{ $this->cartItemCount }}</span>
                <span class="flex items-center gap-1 text-sm uppercase tracking-widest">
                    <x-heroicon-o-shopping-cart class="h-4 w-4" /> Ver carrinho
                </span>
                <span class="text-sm font-bold">R$ {{ number_format($this->cartTotal, 2, ',', '.') }}</span>
            </button>
        </div>
    @endif

    {{-- Drawer do carrinho --}}
    @if ($showCart)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/70" wire:click.self="$set('showCart', false)">
            <div class="flex max-h-[90vh] w-full flex-col rounded-t-3xl bg-gray-900 sm:max-w-lg">
                <div class="flex justify-center pb-1 pt-3">
                    <div class="h-1 w-10 rounded-full bg-gray-600"></div>
                </div>

                <div class="flex items-center justify-between border-b border-white/10 px-4 pb-3">
                    <h3 class="flex items-center gap-2 text-lg font-bold text-white">
                        <x-heroicon-o-shopping-cart class="h-5 w-5 text-[var(--tenant-primary)]" /> Seu pedido
                    </h3>
                    <button wire:click="$set('showCart', false)" class="text-gray-400"><x-heroicon-o-x-mark class="h-6 w-6" /></button>
                </div>

                @unless ($this->businessHours->isOpen)
                    <div class="mx-4 mt-3 flex items-start gap-3 rounded-xl border border-red-700 bg-red-900/60 px-4 py-3">
                        <x-heroicon-o-clock class="h-5 w-5 shrink-0 text-red-400" />
                        <div>
                            <p class="text-sm font-bold text-red-300">Estamos fechados no momento</p>
                            @if ($this->businessHours->message)
                                <p class="mt-0.5 text-xs text-red-400/80">{{ $this->businessHours->message }}</p>
                            @endif
                        </div>
                    </div>
                @endunless

                <div class="flex-1 space-y-1 overflow-y-auto px-4 py-3">
                    @forelse ($this->cartLines as $line)
                        <div wire:key="cart-line-{{ $line['index'] }}" class="border-b border-white/5 py-2">
                            <div class="flex items-center gap-3">
                                @if ($line['image_url'])
                                    <img src="{{ $line['image_url'] }}" alt="{{ $line['name'] }}" class="h-10 w-10 shrink-0 rounded-lg bg-white object-cover">
                                @endif

                                <div class="flex shrink-0 items-center gap-1">
                                    <button wire:click="updateQuantity({{ $line['index'] }}, {{ $line['quantity'] - 1 }})"
                                            class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-700 font-bold text-white transition hover:bg-gray-600">−</button>
                                    <span class="w-6 text-center text-sm font-bold text-white">{{ $line['quantity'] }}</span>
                                    <button wire:click="updateQuantity({{ $line['index'] }}, {{ $line['quantity'] + 1 }})"
                                            class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-700 font-bold text-white transition hover:bg-gray-600">+</button>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm uppercase text-gray-200">{{ $line['name'] }}</p>
                                    @if ($line['original_unit_price'] > $line['unit_price'])
                                        <p class="text-[10px] font-semibold text-orange-400">PROMO — economize R$ {{ number_format(($line['original_unit_price'] - $line['unit_price']) * $line['quantity'], 2, ',', '.') }}</p>
                                    @endif
                                    @foreach ($line['addons_display'] as $addonLine)
                                        <p class="truncate text-[10px] text-gray-500">+ {{ $addonLine }}</p>
                                    @endforeach
                                </div>

                                <div class="shrink-0 text-right">
                                    @if ($line['original_unit_price'] > $line['unit_price'])
                                        <p class="text-xs text-gray-500 line-through">R$ {{ number_format($line['original_unit_price'] * $line['quantity'], 2, ',', '.') }}</p>
                                    @endif
                                    <p class="text-sm font-bold text-green-400">R$ {{ number_format($line['line_total'], 2, ',', '.') }}</p>
                                </div>

                                <button wire:click="removeFromCart({{ $line['index'] }})" class="shrink-0 text-gray-600 transition hover:text-red-400">
                                    <x-heroicon-o-trash class="h-4 w-4" />
                                </button>
                            </div>
                            <input type="text"
                                   wire:blur="updateNote({{ $line['index'] }}, $event.target.value)"
                                   value="{{ $line['note'] }}"
                                   placeholder="Observação (ex: sem cebola, bem passado...)"
                                   maxlength="120"
                                   class="mt-1.5 w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-1.5 text-xs text-gray-300 placeholder-gray-600 focus:border-[var(--tenant-primary)] focus:outline-none">
                        </div>
                    @empty
                        <div class="py-10 text-center">
                            <x-heroicon-o-shopping-cart class="mx-auto h-12 w-12 text-gray-700" />
                            <p class="mt-2 text-sm text-gray-500">Nenhum item adicionado ainda</p>
                        </div>
                    @endforelse
                </div>

                @if (count($this->cartLines) > 0)
                    <div class="space-y-2 border-t border-white/10 px-4 pb-6 pt-3">
                        @if ($this->cartDiscount > 0)
                            <div class="flex items-center justify-between rounded-lg border border-orange-500/30 bg-orange-500/10 px-3 py-1.5">
                                <span class="flex items-center gap-1 text-sm font-semibold text-orange-400">
                                    <x-heroicon-s-tag class="h-4 w-4" /> Desconto promoções
                                </span>
                                <span class="text-sm font-bold text-orange-400">- R$ {{ number_format($this->cartDiscount, 2, ',', '.') }}</span>
                            </div>
                        @endif

                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-gray-400">Total</span>
                            <span class="text-2xl font-bold text-green-400">R$ {{ number_format($this->cartTotal, 2, ',', '.') }}</span>
                        </div>

                        @if ($this->businessHours->isOpen)
                            <a href="{{ route('checkout.index') }}"
                               class="flex items-center justify-center gap-2 rounded-xl bg-[var(--tenant-primary)] py-4 text-center text-sm font-bold uppercase tracking-wide text-white transition active:scale-[0.98]">
                                Continuar <x-heroicon-o-chevron-right class="h-4 w-4" />
                            </a>
                        @else
                            <button type="button" disabled
                                    class="flex w-full cursor-not-allowed items-center justify-center gap-2 rounded-xl bg-gray-700 py-4 text-center text-sm font-bold uppercase tracking-wide text-gray-400">
                                <x-heroicon-o-lock-closed class="h-4 w-4" /> Pedidos fechados
                            </button>
                        @endif

                        <button wire:click="clearCart" wire:confirm="Deseja limpar todo o carrinho?"
                                class="w-full py-1 text-center text-xs text-gray-500 transition hover:text-red-400">
                            Limpar carrinho
                        </button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
