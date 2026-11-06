{{--
    Linha de um item de pedido na aba Pedidos: checkbox que lança/retira só este item e selo
    quando o item já está em outra venda. (Mesma estrutura do item-pedido do Pizzaria-App, sem
    o botão "Dividir" e sem a pessoa da mesa, que o RazelFood ainda não tem.)
--}}
@php
    $state = $this->itemState($orderItem);
    $itemLine = $this->orderItemLine($orderItem);
@endphp
<div wire:key="order-item-{{ $orderItem->id }}" @class([
    'flex items-center gap-2 text-xs',
    'text-gray-600 dark:text-gray-300' => in_array($state['state'], ['free', 'this'], true),
    'text-gray-400 dark:text-gray-500' => in_array($state['state'], ['other', 'paid'], true),
])>
    <input
        type="checkbox"
        wire:loading.attr="disabled"
        @checked($state['state'] === 'this')
        @disabled(in_array($state['state'], ['other', 'paid'], true))
        x-on:change="$wire.toggleOrderItem({{ $orderItem->id }})"
        class="h-3.5 w-3.5 shrink-0 rounded border-gray-300 dark:border-white/20 dark:bg-gray-900 text-primary-600 focus:ring-primary-500 disabled:opacity-40"
    />
    <span class="min-w-0 flex-1">
        {{ $orderItem->quantity }}x @include('filament.tenant.pages.sales.new-sale.item-name', ['line' => $itemLine])
        @if ($state['state'] === 'other')
            <span class="ml-1 inline-flex rounded bg-warning-50 px-1.5 py-0.5 text-[10px] font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">{{ $state['label'] }}</span>
        @elseif ($state['state'] === 'paid')
            <span class="ml-1 inline-flex rounded bg-success-50 px-1.5 py-0.5 text-[10px] font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">pago</span>
        @endif
    </span>
    <span class="shrink-0">R$ {{ number_format($itemLine['total_cents'] / 100, 2, ',', '.') }}</span>
</div>
