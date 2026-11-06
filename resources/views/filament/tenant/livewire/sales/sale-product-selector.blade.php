<div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden">

    {{-- Busca + categorias --}}
    <div class="px-4 pt-4 pb-3 border-b border-gray-100 dark:border-white/10 space-y-3">
        <div class="relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-gray-500 pointer-events-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>
            <input
                wire:model.live.debounce.300ms="search"
                type="text"
                data-busca-input
                placeholder="Buscar produto..."
                class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-xl bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
            />
        </div>

        <div class="flex gap-2 overflow-x-auto pb-1">
            <button wire:click="$set('categoryId', null)" type="button"
                    class="shrink-0 px-3 py-1.5 rounded-full text-xs font-semibold uppercase tracking-wide border transition-colors
                        {{ $categoryId === null ? 'bg-primary-600 border-primary-600 text-white' : 'border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:border-gray-400 dark:hover:border-white/20' }}">
                Todos
            </button>
            @foreach ($this->categories as $category)
                <button wire:click="$set('categoryId', {{ $category->id }})" type="button"
                        class="shrink-0 px-3 py-1.5 rounded-full text-xs font-semibold uppercase tracking-wide border transition-colors
                            {{ $categoryId === $category->id ? 'bg-primary-600 border-primary-600 text-white' : 'border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:border-gray-400 dark:hover:border-white/20' }}">
                    {{ $category->name }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Grid de produtos: um clique dispara "produto-selecionado" para a Page pai --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 p-4 overflow-y-auto" style="max-height: clamp(18rem, 55vh, 44rem)">
        @forelse ($this->products as $product)
            @php
                $price = $this->priceOf($product);
                $precoVenda = $price['original'];
                $temPromo = $price['onPromo'];
                $precoExibido = $price['price'];
            @endphp
            <button
                wire:key="product-{{ $product->id }}"
                wire:click="selectProduct({{ $product->id }})"
                wire:loading.attr="disabled"
                wire:target="selectProduct({{ $product->id }})"
                type="button"
                class="flex flex-col text-left rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden hover:border-primary-400 dark:hover:border-primary-500 hover:shadow-sm transition-all disabled:opacity-50"
            >
                <div class="relative aspect-square bg-gray-100 dark:bg-gray-900 flex items-center justify-center overflow-hidden">
                    @if ($product->image_url)
                        <img src="{{ $product->image_url }}" alt="{{ $product->name }}" class="w-full h-full object-cover">
                    @else
                        <x-filament::icon icon="heroicon-o-cake" class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                    @endif
                    @if ($temPromo)
                        <span class="absolute top-1 left-1 bg-orange-500 text-white text-[9px] font-bold px-1 py-0.5 rounded">PROMO</span>
                    @endif
                </div>
                <div class="p-2 space-y-0.5">
                    @if ($product->category)
                        <span class="inline-block text-[9px] font-semibold uppercase tracking-wide text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-500/10 rounded px-1">{{ $product->category->name }}</span>
                    @endif
                    <p class="text-xs font-medium text-gray-800 dark:text-gray-200 leading-tight line-clamp-2">{{ $product->name }}</p>
                    @if ($temPromo)
                        <p class="flex items-center gap-1">
                            <span class="text-[10px] text-gray-400 dark:text-gray-500 line-through">R$ {{ number_format($precoVenda, 2, ',', '.') }}</span>
                            <span class="text-xs font-semibold text-orange-600 dark:text-orange-400">R$ {{ number_format($precoExibido, 2, ',', '.') }}</span>
                        </p>
                    @else
                        <p class="text-xs font-semibold text-primary-700 dark:text-primary-400">R$ {{ number_format($precoExibido, 2, ',', '.') }}</p>
                    @endif
                    @if ($product->controls_stock)
                        @php $saldo = (float) $product->stock_quantity; @endphp
                        <span class="inline-flex items-center gap-0.5 text-[9px] font-semibold {{ $saldo > 0 ? 'text-gray-400 dark:text-gray-500' : 'text-red-500 dark:text-red-400' }}">
                            <x-filament::icon icon="heroicon-o-archive-box" class="h-2.5 w-2.5" />
                            {{ $saldo == floor($saldo) ? (int) $saldo : number_format($saldo, 2, ',', '.') }} {{ $product->commercial_unit }}
                        </span>
                    @endif
                </div>
            </button>
        @empty
            <div class="col-span-full text-center text-sm text-gray-400 dark:text-gray-500 py-10">
                Nenhum produto encontrado.
            </div>
        @endforelse
    </div>
</div>
