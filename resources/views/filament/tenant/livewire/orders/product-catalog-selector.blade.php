<div class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Buscar produto..."
            class="fi-input block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-2 focus:ring-rf-orange-600 dark:border-none dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
        >
    </div>

    @php($activeRoot = $this->categories->firstWhere('id', $categoryId))

    <div class="flex flex-wrap gap-2">
        <button
            type="button"
            wire:click="selectCategory(null)"
            @class([
                'rounded-full px-3 py-1.5 text-xs font-semibold uppercase tracking-wide transition',
                'bg-rf-orange-600 text-white' => $categoryId === null,
                'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => $categoryId !== null,
            ])
        >
            Todas
        </button>

        @foreach ($this->categories as $category)
            <button
                type="button"
                wire:click="selectCategory({{ $category->id }})"
                @class([
                    'inline-flex items-center gap-1.5 rounded-full py-1 pl-1 pr-3 text-xs font-semibold uppercase tracking-wide transition',
                    'bg-rf-orange-600 text-white' => $categoryId === $category->id,
                    'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => $categoryId !== $category->id,
                ])
            >
                @if ($category->nav_thumbnail_url)
                    <img src="{{ $category->nav_thumbnail_url }}" alt="" class="h-5 w-5 shrink-0 rounded-full bg-white object-cover">
                @else
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-gray-200 text-gray-400 dark:bg-white/10">
                        <x-heroicon-o-photo class="h-3 w-3" />
                    </span>
                @endif
                {{ $category->name }}
            </button>
        @endforeach
    </div>

    @if ($activeRoot && $activeRoot->children->isNotEmpty())
        <div class="flex flex-wrap gap-1.5">
            <button
                type="button"
                wire:click="selectSubcategory(null)"
                @class([
                    'rounded-full px-2.5 py-1 text-[11px] font-semibold transition',
                    'bg-rf-orange-600 text-white' => $subcategoryId === null,
                    'bg-gray-100 text-gray-500 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-400 dark:hover:bg-white/10' => $subcategoryId !== null,
                ])
            >
                Todos
            </button>

            @foreach ($activeRoot->children as $child)
                <button
                    type="button"
                    wire:click="selectSubcategory({{ $child->id }})"
                    @class([
                        'rounded-full px-2.5 py-1 text-[11px] font-semibold transition',
                        'bg-rf-orange-600 text-white' => $subcategoryId === $child->id,
                        'bg-gray-100 text-gray-500 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-400 dark:hover:bg-white/10' => $subcategoryId !== $child->id,
                    ])
                >
                    {{ $child->name }}
                </button>
            @endforeach
        </div>
    @endif

    <div class="max-h-[calc(100dvh-16rem)] overflow-y-auto pr-1 sm:max-h-[calc(100dvh-14rem)]">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
        @forelse ($this->products as $product)
            <button
                type="button"
                wire:click="selectProduct({{ $product->id }})"
                wire:key="catalog-product-{{ $product->id }}"
                class="group relative flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white text-left transition hover:border-rf-orange-600/60 hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10"
            >
                <div class="flex aspect-square items-center justify-center overflow-hidden bg-gray-100 dark:bg-black/20">
                    @if ($product->image_url)
                        <img src="{{ $product->image_url }}" alt="{{ $product->name }}" class="h-full w-full object-cover">
                    @else
                        <x-heroicon-o-photo class="h-8 w-8 text-gray-400 dark:text-gray-600" />
                    @endif

                    @if ($product->low_stock)
                        <span class="absolute left-1.5 top-1.5 flex items-center gap-1 rounded-full bg-rf-amber-300 px-2 py-0.5 text-[10px] font-bold text-rf-navy-900">
                            <x-heroicon-o-exclamation-triangle class="h-3 w-3" />
                            Baixo
                        </span>
                    @endif

                    <span class="absolute bottom-1.5 right-1.5 flex h-7 w-7 items-center justify-center rounded-full bg-rf-orange-600 text-white shadow group-hover:bg-rf-orange-700">
                        <x-heroicon-o-plus class="h-4 w-4" />
                    </span>
                </div>

                <div class="flex flex-1 flex-col gap-1 p-2.5">
                    @if ($product->category)
                        <span class="max-w-full truncate rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/10 dark:text-gray-400">
                            {{ $product->category->name }}
                        </span>
                    @endif
                    <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $product->name }}</span>
                    <span class="text-sm font-bold text-rf-orange-600">
                        {{ \Illuminate\Support\Number::currency($product->resolved_price, in: 'BRL', locale: 'pt_BR') }}
                    </span>
                </div>
            </button>
        @empty
            <p class="col-span-full py-8 text-center text-sm text-gray-500 dark:text-gray-500">Nenhum produto encontrado.</p>
        @endforelse
        </div>
    </div>
</div>
