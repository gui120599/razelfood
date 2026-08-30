<x-filament-widgets::widget>
    <x-filament::section heading="Mais vendidos no período">
        @php($rows = $this->topProducts())

        @if (empty($rows))
            <p class="text-sm text-gray-500 dark:text-gray-400">Nenhuma venda no período selecionado.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs font-semibold uppercase text-gray-400 dark:border-white/10">
                            <th class="py-2 pr-3">#</th>
                            <th class="py-2 pr-3">Produto</th>
                            <th class="py-2 pr-3 text-right">Qtd. vendida</th>
                            <th class="py-2 text-right">Faturamento</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows as $index => $row)
                            <tr>
                                <td class="py-2 pr-3 text-gray-400">{{ $index + 1 }}</td>
                                <td class="py-2 pr-3 text-gray-700 dark:text-gray-200">{{ $row['name'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-200">
                                    {{ rtrim(rtrim(number_format($row['quantity'], 2, ',', '.'), '0'), ',') }}
                                </td>
                                <td class="py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">
                                    R$ {{ number_format($row['revenue'], 2, ',', '.') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
