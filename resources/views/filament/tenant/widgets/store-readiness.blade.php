<x-filament-widgets::widget>
    <x-filament::section heading="Configuração da loja">
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($this->checklist() as $item)
                <li class="flex items-center justify-between gap-3 py-2.5">
                    <a href="{{ $item['url'] }}" class="flex items-center gap-2 text-sm font-medium text-gray-700 hover:underline dark:text-gray-200">
                        @if ($item['done'])
                            <x-heroicon-o-check-circle class="h-5 w-5 shrink-0 text-success-500" />
                        @else
                            <x-heroicon-o-x-circle class="h-5 w-5 shrink-0 text-gray-400" />
                        @endif

                        {{ $item['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
