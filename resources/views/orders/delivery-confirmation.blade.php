<x-layouts.public :tenant="$tenant">
    <div class="mx-auto max-w-md space-y-4">
        <div class="rounded-lg bg-white p-6 text-center shadow-sm">
            <p class="text-sm text-gray-500">Pedido #{{ $order->id }}</p>
            <p class="mt-1 text-lg font-semibold">{{ $order->client?->name }}</p>
            <p class="mt-1 text-sm text-gray-500">{{ $order->delivery_address ?? 'Retirada no local' }}</p>

            @if ($error)
                <p class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-600">{{ $error }}</p>
            @elseif ($order->status === \App\Enums\OrderStatus::Delivered)
                <p class="mt-4 rounded-lg bg-green-50 p-3 text-sm font-semibold text-green-700">
                    Entrega confirmada{{ $order->delivered_at ? ' às '.$order->delivered_at->format('H:i') : '' }}.
                </p>
            @else
                <p class="mt-4 text-sm text-gray-600">Confirme quando entregar este pedido ao cliente.</p>

                <form method="POST" action="{{ url()->full() }}" class="mt-4">
                    @csrf
                    <button
                        type="submit"
                        class="w-full rounded-xl bg-[var(--tenant-primary)] py-3 text-sm font-bold uppercase tracking-wide text-white shadow-lg"
                    >
                        Confirmar entrega
                    </button>
                </form>
            @endif
        </div>
    </div>
</x-layouts.public>
