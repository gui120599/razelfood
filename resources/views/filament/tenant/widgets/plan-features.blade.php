<x-filament-widgets::widget>
    <x-filament::section heading="Plano e funcionalidades">
        @if ($plan = $this->planName())
            <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">
                Plano atual: <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $plan }}</span>
            </p>
        @endif

        <ul class="space-y-2">
            @forelse ($this->enabledFeatures() as $feature)
                <li class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <x-heroicon-o-check-circle class="h-5 w-5 shrink-0 text-success-500" />
                    {{ $feature->name }}
                </li>
            @empty
                <li class="text-sm text-gray-500 dark:text-gray-400">Nenhuma funcionalidade habilitada.</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
