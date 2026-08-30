@props(['product', 'category' => null, 'badge' => null])

@php
    $onPromo = (float) $product->resolved_price < (float) $product->resolved_original_price;
    $hasFlavorOptions = $category?->allows_flavors
        && $category->flavorQuantityOptions->isNotEmpty()
        && ! ($product->resolved_flavor_combo_blocked ?? false);
@endphp

<div {{ $attributes->class(['relative']) }}>
    <button type="button" wire:click="viewProduct({{ $product->id }})" class="block w-full text-left">
        <div class="flex items-start gap-3 rounded-xl p-2 transition hover:bg-white/5">
            <div class="relative w-2/5 shrink-0">
                @if ($product->image_url)
                    <img src="{{ $product->image_url }}" alt="{{ $product->name }}"
                         class="h-28 w-full rounded-lg bg-white object-cover">
                @else
                    <div class="flex h-28 w-full items-center justify-center rounded-lg bg-gray-800 text-gray-600">
                        <x-heroicon-o-photo class="h-8 w-8" />
                    </div>
                @endif

                @if ($onPromo)
                    <span class="absolute left-1 top-1 flex items-center gap-0.5 rounded bg-orange-500 px-1 py-0.5 text-[10px] font-bold text-white">
                        <x-heroicon-s-tag class="h-3 w-3" /> PROMO
                    </span>
                @endif

                @if ($badge === 'bestseller')
                    <span @class([
                        'absolute left-1 flex items-center gap-0.5 rounded bg-yellow-500 px-1 py-0.5 text-[10px] font-bold text-white',
                        'top-1' => ! $onPromo,
                        'bottom-1' => $onPromo,
                    ])>
                        <x-heroicon-s-star class="h-3 w-3" /> + VENDIDO
                    </span>
                @endif
            </div>

            <div class="flex h-28 min-w-0 flex-1 flex-col justify-center gap-1">
                <h3 class="truncate text-base font-semibold uppercase text-gray-100">{{ $product->name }}</h3>

                @if ($product->description)
                    <p class="line-clamp-2 text-xs text-gray-400">{{ $product->description }}</p>
                @endif

                <div class="pt-1">
                    @if ($onPromo)
                        <p class="text-xs text-gray-500 line-through">De R$ {{ number_format($product->resolved_original_price, 2, ',', '.') }}</p>
                        <p class="text-lg font-bold text-green-400">R$ {{ number_format($product->resolved_price, 2, ',', '.') }}</p>
                    @else
                        <p class="text-lg font-bold text-white">R$ {{ number_format($product->resolved_price, 2, ',', '.') }}</p>
                    @endif

                    @if ($category?->allows_flavors && ($product->resolved_flavor_combo_blocked ?? false))
                        <p class="text-[10px] font-semibold uppercase text-orange-300">Promoção só na unidade inteira</p>
                    @endif
                </div>
            </div>
        </div>
    </button>

    <div class="absolute bottom-3 right-2 z-10">
        @if ($hasFlavorOptions)
            <button wire:click.stop="startCombo({{ $category->id }}, {{ $product->id }})"
                    class="flex h-8 w-8 items-center justify-center rounded-full bg-[var(--tenant-primary)] text-white shadow-lg transition active:scale-90">
                <x-heroicon-o-plus class="h-5 w-5" />
            </button>
        @elseif ($product->resolved_has_addons ?? false)
            <button wire:click.stop="viewProduct({{ $product->id }})"
                    class="flex h-8 w-8 items-center justify-center rounded-full bg-[var(--tenant-primary)] text-white shadow-lg transition active:scale-90">
                <x-heroicon-o-plus class="h-5 w-5" />
            </button>
        @else
            <button wire:click.stop="addToCart({{ $product->id }})"
                    class="flex h-8 w-8 items-center justify-center rounded-full bg-[var(--tenant-primary)] text-white shadow-lg transition active:scale-90">
                <x-heroicon-o-plus class="h-5 w-5" />
            </button>
        @endif
    </div>

    <hr class="mt-1 border-white/10">
</div>
