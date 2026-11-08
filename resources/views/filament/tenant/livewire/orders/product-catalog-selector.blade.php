<div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden">

    {{-- ── Busca + categorias ─────────────────────────────────────────────── --}}
    <div class="px-4 pt-4 pb-3 border-b border-gray-100 dark:border-white/10 space-y-3">

        {{-- Busca --}}
        <div class="relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-gray-500 pointer-events-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>
            <input
                wire:model.live.debounce.300ms="search"
                type="text"
                placeholder="Buscar produto..."
                class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-xl bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
            />
        </div>

        {{-- Categorias: ícones quadrados (estilo cardápio) --}}
        <div class="flex gap-3 overflow-x-auto pb-1">

            {{-- Todos --}}
            <button wire:click="selectCategory(null)" type="button"
                    class="flex flex-col items-center gap-1 shrink-0 w-[4.5rem] focus:outline-none">
                <div class="w-14 h-14 rounded-xl overflow-hidden border-2 transition-colors duration-150 bg-gray-100 dark:bg-gray-900 flex items-center justify-center
                    {{ $categoryId === null ? 'border-orange-500' : 'border-gray-300 dark:border-white/10 hover:border-gray-400 dark:hover:border-white/20' }}">
                    <svg class="w-7 h-7 text-gray-500 dark:text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
                    </svg>
                </div>
                <span class="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase text-center">TODOS</span>
            </button>

            @foreach ($this->categories as $category)
                <button wire:click="selectCategory({{ $category->id }})" type="button"
                        wire:key="catalog-category-{{ $category->id }}"
                        class="flex flex-col items-center gap-1 shrink-0 w-[4.5rem] focus:outline-none">
                    <div class="w-14 h-14 rounded-xl overflow-hidden border-2 transition-colors duration-150 shadow-sm bg-gray-100 dark:bg-gray-900 flex items-center justify-center
                        {{ $categoryId === $category->id ? 'border-orange-500' : 'border-gray-300 dark:border-white/10 hover:border-gray-400 dark:hover:border-white/20' }}">
                        @if ($category->nav_thumbnail_url)
                            <img src="{{ $category->nav_thumbnail_url }}" alt="{{ $category->name }}" width="56" height="56" loading="lazy" decoding="async" class="w-full h-full object-cover">
                        @else
                            <x-heroicon-o-photo class="w-6 h-6 text-gray-300 dark:text-gray-600" />
                        @endif
                    </div>
                    <span class="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase leading-tight text-center line-clamp-2 w-full">{{ $category->name }}</span>
                </button>
            @endforeach
        </div>

        {{-- Subcategorias da categoria escolhida (o RazelFood tem categorias em dois níveis) --}}
        @php $activeRoot = $this->categories->firstWhere('id', $categoryId); @endphp
        @if ($activeRoot && $activeRoot->children->isNotEmpty())
            <div class="flex flex-wrap gap-1.5">
                <button type="button" wire:click="selectSubcategory(null)"
                    @class([
                        'rounded-full border px-2.5 py-1 text-[11px] font-semibold transition',
                        'bg-primary-600 border-primary-600 text-white' => $subcategoryId === null,
                        'border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:border-gray-400 dark:hover:border-white/20' => $subcategoryId !== null,
                    ])>Todos</button>

                @foreach ($activeRoot->children as $child)
                    <button type="button" wire:click="selectSubcategory({{ $child->id }})" wire:key="catalog-subcategory-{{ $child->id }}"
                        @class([
                            'rounded-full border px-2.5 py-1 text-[11px] font-semibold transition',
                            'bg-primary-600 border-primary-600 text-white' => $subcategoryId === $child->id,
                            'border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:border-gray-400 dark:hover:border-white/20' => $subcategoryId !== $child->id,
                        ])>{{ $child->name }}</button>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ── Lista de produtos ───────────────────────────────────────────────── --}}
    <div class="divide-y divide-gray-100 dark:divide-white/10 overflow-y-auto" style="max-height: clamp(18rem, 60vh, 48rem)">
        @forelse ($this->products as $product)
            @php
                $precoVenda = $product->resolved_original_price;
                $precoExibido = $product->resolved_price;
                $temDesconto = $precoExibido < $precoVenda;
            @endphp
            <div wire:key="catalog-product-{{ $product->id }}"
                 class="relative p-2 flex items-start gap-2 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">

                {{-- Imagem --}}
                <div class="relative shrink-0">
                    @if ($product->image_url)
                        <img src="{{ $product->image_url }}"
                             alt="{{ $product->name }}"
                             width="80" height="64" loading="lazy" decoding="async"
                             class="w-20 h-16 object-cover rounded-lg bg-gray-100 dark:bg-gray-900">
                    @else
                        <div class="w-20 h-16 rounded-lg bg-gray-100 dark:bg-gray-900 flex items-center justify-center">
                            <x-heroicon-o-photo class="w-6 h-6 text-gray-300 dark:text-gray-600" />
                        </div>
                    @endif
                    @if ($temDesconto)
                        <span class="absolute top-1 left-1 bg-orange-500 text-white text-xs font-bold px-1 py-0.5 rounded flex items-center gap-0.5">
                            <x-heroicon-s-tag class="w-3 h-3" /> PROMO
                        </span>
                    @endif
                </div>

                {{-- Info --}}
                <div class="flex-1 min-w-0 flex flex-col justify-between pr-12 min-h-[4rem]">
                    <div>
                        @if ($product->category)
                            <span class="inline-block text-xs font-semibold uppercase tracking-wide text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-500/10 rounded px-1 mb-0.5">{{ $product->category->name }}</span>
                        @endif
                        <p class="text-gray-800 dark:text-gray-200 text-sm font-semibold leading-snug line-clamp-2">{{ $product->name }}</p>
                    </div>
                    <div>
                        @if ($temDesconto)
                            <span class="text-gray-400 dark:text-gray-500 text-xs line-through block leading-tight">R$ {{ number_format($precoVenda, 2, ',', '.') }}</span>
                            <span class="text-green-600 dark:text-green-400 text-base font-bold">R$ {{ number_format($precoExibido, 2, ',', '.') }}</span>
                        @else
                            <span class="text-gray-800 dark:text-gray-200 text-base font-bold">R$ {{ number_format($precoExibido, 2, ',', '.') }}</span>
                        @endif
                        @if ($product->description)
                            <span class="block text-xs text-gray-400 dark:text-gray-500 leading-tight mt-0.5 line-clamp-2">{{ $product->description }}</span>
                        @endif
                        @if ($product->controls_stock)
                            @php $saldo = (float) $product->stock_quantity; @endphp
                            <span class="inline-flex items-center gap-0.5 text-xs font-semibold mt-0.5 {{ $saldo > 0 ? 'text-gray-400 dark:text-gray-500' : 'text-red-500 dark:text-red-400' }}">
                                <x-heroicon-s-archive-box class="w-3 h-3" />
                                {{ $saldo == floor($saldo) ? (int) $saldo : number_format($saldo, 2, ',', '.') }} {{ $product->commercial_unit ?: 'UN' }} em estoque
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Botão + --}}
                <button
                    wire:click="selectProduct({{ $product->id }})"
                    wire:loading.attr="disabled"
                    wire:target="selectProduct({{ $product->id }})"
                    type="button"
                    class="absolute bottom-1.5 right-1.5 w-11 h-11 rounded-full bg-primary-600 hover:bg-primary-500 active:scale-90 text-white text-2xl font-bold flex items-center justify-center shadow transition-all z-10"
                >+</button>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-12 text-gray-400 dark:text-gray-500">
                <svg class="w-10 h-10 mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                <p class="text-sm">Nenhum produto encontrado</p>
            </div>
        @endforelse
    </div>
</div>
