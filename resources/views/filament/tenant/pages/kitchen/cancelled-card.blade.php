{{--
    Card enxuto para a faixa de cancelados (cópia traduzida do original): sem ações de transição
    (pedido cancelado é terminal), só o essencial para entender o que aconteceu.
--}}
<div wire:key="cancelled-{{ $order->id }}" class="rounded-lg border border-danger-200 bg-white p-3 text-sm dark:border-danger-500/20 dark:bg-gray-900">
    <div class="flex items-start justify-between gap-2">
        <span class="font-bold text-gray-950 dark:text-white">#{{ $order->id }}</span>
        <span class="text-xs text-gray-400">{{ $order->cancelled_at?->format('H:i') }}</span>
    </div>

    @if ($order->client_id)
        <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $order->client?->name }}</p>
    @endif

    <p class="mt-1 text-xs font-semibold text-danger-700 dark:text-danger-400">
        {{ $order->cancellation_reason?->label() ?? 'Motivo não informado' }}
    </p>

    <p class="text-xs text-gray-400">
        Por: {{ $order->cancelledBy?->name ?? 'Cliente / não identificado' }}
    </p>

    <button
        type="button"
        wire:click="mountAction('viewDetails', { order: {{ $order->id }} })"
        class="mt-2 text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400"
    >
        Ver detalhes
    </button>
</div>
