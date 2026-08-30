<div wire:poll.15s="$refresh" class="rounded-xl border border-white/10 bg-gray-900 p-4">
    <div class="flex items-center justify-between">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Pedido #{{ $order->id }}</p>
        @if ($order->status === \App\Enums\OrderStatus::Cancelled)
            <span class="rounded-full bg-rf-danger/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-rf-danger">Cancelado</span>
        @endif
    </div>

    <p class="mt-1 flex items-center gap-2 text-xl font-bold text-white">
        <x-dynamic-component :component="'heroicon-s-'.$order->status->icon()" class="h-5 w-5 shrink-0 text-[var(--tenant-primary)]" />
        {{ $order->status->label() }}
    </p>

    @if ($order->status !== \App\Enums\OrderStatus::Cancelled)
        @php
            $steps = [
                \App\Enums\OrderStatus::Open,
                \App\Enums\OrderStatus::Preparing,
                \App\Enums\OrderStatus::Ready,
                \App\Enums\OrderStatus::InTransit,
                \App\Enums\OrderStatus::Finished,
            ];
            $currentIndex = array_search($order->status, $steps, true);
        @endphp
        <ol class="mt-5 flex items-start">
            @foreach ($steps as $index => $step)
                @php
                    $isDone = $currentIndex !== false && $index < $currentIndex;
                    $isCurrent = $currentIndex !== false && $index === $currentIndex;
                    $isUpcoming = $currentIndex === false || $index > $currentIndex;
                    $timestamp = $order->{$step->timestampColumn()};
                @endphp
                <li wire:key="timeline-step-{{ $step->value }}"
                    class="flex flex-1 flex-col items-center text-center {{ $index < count($steps) - 1 ? 'relative after:absolute after:left-1/2 after:top-4 after:-z-10 after:h-0.5 after:w-full after:content-[\'\']' : '' }} {{ $isDone || $isCurrent ? 'after:bg-[var(--tenant-primary)]' : 'after:bg-gray-700' }} after:transition-colors after:duration-500">
                    <span class="relative flex h-8 w-8 shrink-0 items-center justify-center">
                        @if ($isCurrent)
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-[var(--tenant-primary)] opacity-40"></span>
                        @endif
                        <span @class([
                            'relative flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-4 ring-gray-900 transition-all duration-500',
                            'bg-[var(--tenant-primary)]' => $isDone || $isCurrent,
                            'bg-gray-800 border border-gray-700' => $isUpcoming,
                        ])>
                            @if ($isDone)
                                <x-heroicon-s-check class="h-4 w-4 text-white" />
                            @else
                                <x-dynamic-component :component="($isCurrent ? 'heroicon-s-' : 'heroicon-o-').$step->icon()"
                                                      @class(['h-4 w-4', 'text-white' => $isCurrent, 'text-gray-500' => $isUpcoming])
                                />
                            @endif
                        </span>
                    </span>

                    <span @class([
                        'mt-2 text-[11px] font-medium leading-tight transition-colors duration-500',
                        'text-white font-bold' => $isCurrent,
                        'text-gray-300' => $isDone,
                        'text-gray-600' => $isUpcoming,
                    ])>
                        {{ $step->label() }}
                    </span>

                    @if (($isDone || $isCurrent) && $timestamp)
                        <span class="text-[10px] text-gray-500">{{ $timestamp->format('H:i') }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    @else
        <p class="mt-2 flex items-center gap-1.5 text-sm text-rf-danger">
            <x-heroicon-o-x-circle class="h-4 w-4 shrink-0" />
            {{ $order->cancellation_reason?->label() ?? 'Pedido cancelado' }}
        </p>
    @endif

    <p class="mt-4 flex items-center gap-1.5 text-[11px] text-gray-500">
        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" /> Atualiza automaticamente a cada 15s
    </p>
</div>
