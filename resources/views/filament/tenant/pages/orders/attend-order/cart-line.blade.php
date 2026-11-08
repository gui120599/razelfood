{{-- Linha de um item do carrinho da Nova Pedido. Mesma estrutura do partial pedido-item-linha do
     Pizzaria-App (imagem, badge da categoria, sabores um por linha, adicionais, − qtd +, valor, editar,
     remover), sem os selos de cortesia/relâmpago/oferta e sem o cliente da mesa, que o RazelFood não
     tem. O número do item é o índice do carrinho (a AttendOrder é a dona do estado). --}}
<div wire:key="cart-line-{{ $line['index'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 bg-white dark:bg-gray-800"
    x-data="{ noteOpen: @js(filled($line['note'])) }">

    {{-- Imagem --}}
    @if ($line['image_url'])
        <img src="{{ $line['image_url'] }}" alt="{{ $line['name'] }}"
             class="w-10 h-10 object-cover rounded-lg shrink-0 bg-gray-100 dark:bg-gray-900"
             onerror="this.style.display='none'">
    @else
        <div class="w-10 h-10 rounded-lg bg-gray-100 dark:bg-gray-900 flex items-center justify-center shrink-0">
            <x-heroicon-o-photo class="w-4 h-4 text-gray-400 dark:text-gray-500" />
        </div>
    @endif

    {{-- Info --}}
    <div class="flex-1 min-w-[9rem]">
        <div class="flex flex-wrap items-center gap-1 mb-0.5">
            @if ($line['category_name'])
                <span class="inline-block text-xs font-semibold uppercase tracking-wide text-primary-700 dark:text-primary-400 bg-primary-50 dark:bg-primary-500/10 border border-primary-200 dark:border-primary-500/20 rounded px-1.5 py-0.5">{{ $line['category_name'] }}</span>
            @endif
        </div>
        @if ($line['flavor_lines'] !== [])
            {{-- Pizza de vários sabores: um sabor por linha ("MEIA Calabresa"). --}}
            @foreach ($line['flavor_lines'] as $flavorLine)
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ $flavorLine }}</p>
            @endforeach
        @else
            <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ $line['name'] }}</p>
        @endif
        @foreach ($line['addons_display'] as $addonLine)
            <p class="text-xs text-gray-500 dark:text-gray-400 truncate">+ {{ $addonLine }}</p>
        @endforeach
        @foreach ($line['gifts_display'] ?? [] as $giftLine)
            <p class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 truncate">{{ $giftLine }} — grátis</p>
        @endforeach
        @if (filled($line['note']))
            <p x-show="! noteOpen" class="text-xs text-gray-400 dark:text-gray-500 italic truncate">{{ $line['note'] }}</p>
        @endif
    </div>

    {{-- Quantidade --}}
    <div class="flex items-center gap-1 shrink-0">
        <button wire:click="updateItemQuantity({{ $line['index'] }}, {{ $line['quantity'] - 1 }})" type="button"
            class="w-7 h-7 rounded-full bg-gray-100 hover:bg-red-100 dark:bg-white/5 dark:hover:bg-red-500/10 text-gray-600 dark:text-gray-300 flex items-center justify-center text-base font-bold leading-none transition-colors">−</button>
        <span class="w-8 text-center text-sm font-semibold text-gray-700 dark:text-gray-300 tabular-nums">{{ $line['quantity'] }}</span>
        <button wire:click="updateItemQuantity({{ $line['index'] }}, {{ $line['quantity'] + 1 }})" type="button"
            class="w-7 h-7 rounded-full bg-gray-100 hover:bg-green-100 dark:bg-white/5 dark:hover:bg-green-500/10 text-gray-600 dark:text-gray-300 flex items-center justify-center text-base font-bold leading-none transition-colors">+</button>
    </div>

    {{-- Valor --}}
    <div class="text-right shrink-0 min-w-[5rem]">
        <p class="text-sm font-bold text-gray-800 dark:text-gray-200">R$ {{ number_format($line['line_total'], 2, ',', '.') }}</p>
    </div>

    {{-- Adicionais (só quando o produto tem) --}}
    @if ($line['has_addons'])
        <button wire:click="editLineAddons({{ $line['index'] }})" type="button"
            class="w-7 h-7 rounded-full bg-gray-100 hover:bg-blue-100 dark:bg-white/5 dark:hover:bg-blue-500/10 text-gray-400 hover:text-blue-600 dark:text-gray-500 dark:hover:text-blue-400 flex items-center justify-center shrink-0 transition-colors"
            title="Editar adicionais">
            <x-heroicon-o-plus-circle class="w-3.5 h-3.5" />
        </button>
    @endif

    {{-- Editar observação --}}
    <button x-on:click="noteOpen = ! noteOpen; $nextTick(() => $refs.noteInput?.focus())" type="button"
        class="w-7 h-7 rounded-full bg-gray-100 hover:bg-blue-100 dark:bg-white/5 dark:hover:bg-blue-500/10 text-gray-400 hover:text-blue-600 dark:text-gray-500 dark:hover:text-blue-400 flex items-center justify-center shrink-0 transition-colors"
        title="Editar observação">
        <x-heroicon-s-pencil class="w-3.5 h-3.5" />
    </button>

    {{-- Remover --}}
    <button wire:click="removeItem({{ $line['index'] }})"
        wire:confirm="Remover '{{ $line['name'] }}' do pedido?"
        type="button"
        class="w-7 h-7 rounded-full bg-red-50 hover:bg-red-100 dark:bg-red-500/10 dark:hover:bg-red-500/20 text-red-500 dark:text-red-400 flex items-center justify-center shrink-0 transition-colors"
        title="Remover">
        <x-heroicon-s-trash class="w-3.5 h-3.5" />
    </button>

    <input x-show="noteOpen" x-ref="noteInput" type="text" value="{{ $line['note'] }}"
        wire:change="updateItemNote({{ $line['index'] }}, $event.target.value)"
        placeholder="Obs.: sem cebola, bem passado..."
        class="basis-full w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500">
</div>
