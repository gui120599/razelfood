<x-filament-panels::page>
    @php
        $summary = $this->report->summary();
        $money = fn (int $cents): string => 'R$ '.number_format($cents / 100, 2, ',', '.');
        $dimensions = \App\Support\Fiscal\FiscalReportQuery::DIMENSIONS;
        $titles = \App\Support\Fiscal\FiscalReportQuery::TITLES;
        $notesLimit = 200;
        $notes = $this->report->notes($notesLimit);
        $notesTotals = $this->report->notesTotals();
        $withoutItems = $this->report->notesWithoutItems();
        $unusedLimit = 200;
        $unused = $this->report->unusedNumbers($unusedLimit);
    @endphp

    <div class="flex flex-wrap items-end gap-4">
        <label class="text-sm text-gray-700 dark:text-gray-300">
            Autorizadas de
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="startDate" />
            </x-filament::input.wrapper>
        </label>
        <label class="text-sm text-gray-700 dark:text-gray-300">
            até
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="endDate" />
            </x-filament::input.wrapper>
        </label>
        <div class="ml-auto flex gap-2">
            <x-filament::button tag="a" color="gray" icon="heroicon-o-table-cells" :href="route('reports.fiscal.csv', $this->exportParameters())">CSV / Excel</x-filament::button>
            <x-filament::button tag="a" color="gray" icon="heroicon-o-printer" target="_blank" :href="route('reports.fiscal.print', $this->exportParameters())">Imprimir / PDF</x-filament::button>
        </div>
    </div>

    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <p class="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">Agrupamentos exibidos</p>
        <div class="flex flex-wrap gap-x-4 gap-y-4">
            @foreach ($dimensions as $key => $name)
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <x-filament::input.checkbox wire:model.live="groupings" :value="$key" />
                    {{ $name }}
                </label>
            @endforeach
        </div>
    </div>

    @if ($withoutItems > 0)
        <div class="rounded-xl bg-warning-50 p-4 text-sm text-warning-700 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/30">
            {{ $withoutItems }} nota(s) do período não têm o detalhe de itens (foram emitidas antes deste relatório): aparecem na lista de notas e na forma de pagamento, mas ficam fora dos agrupamentos por item.
        </div>
    @endif

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ([
            ['Notas autorizadas', $summary['notes']],
            ['Itens', $summary['items']],
            ['Valor dos itens', $money($summary['items_cents'])],
            ['Adicionais e frete', $money($summary['others_cents'])],
            ['Total das notas', $money($summary['total_cents'])],
        ] as [$label, $value])
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    @foreach ($dimensions as $key => $name)
        @if (in_array($key, $this->groupings, true))
            <div wire:key="group-{{ $key }}">
                <h3 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">{{ $titles[$key] }}</h3>
                <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    @include('filament.tenant.pages.partials.fiscal-report-group', ['dimension' => $key, 'title' => $name, 'group' => $this->report->group($key)])
                </div>
            </div>
        @endif
    @endforeach

    <details class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-950 dark:text-white">Notas do período ({{ $notesTotals['count'] }})</summary>
        <div class="overflow-x-auto">
            <table class="w-full divide-y divide-gray-100 text-sm dark:divide-white/10">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2">Autorização</th>
                        <th class="px-4 py-2">Nº / série</th>
                        <th class="px-4 py-2">Venda</th>
                        <th class="px-4 py-2">Cliente</th>
                        <th class="px-4 py-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($notes as $note)
                        <tr wire:key="note-{{ $loop->index }}">
                            <td class="whitespace-nowrap px-4 py-2">{{ $note['authorized_at']->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-2">{{ $note['number'] }} / {{ $note['series'] }}</td>
                            <td class="px-4 py-2">{{ $note['sale'] }}</td>
                            <td class="px-4 py-2">{{ $note['client'] }}</td>
                            <td class="px-4 py-2 text-right">{{ $money($note['total_cents']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50 font-semibold dark:bg-white/5">
                    <tr><td colspan="4" class="px-4 py-2">TOTAL ({{ $notesTotals['count'] }} nota(s))</td><td class="px-4 py-2 text-right">{{ $money($notesTotals['total_cents']) }}</td></tr>
                </tfoot>
            </table>
            @if ($notesTotals['count'] > $notes->count())
                <p class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">Mostrando as {{ $notes->count() }} primeiras de {{ $notesTotals['count'] }}; o CSV e a impressão trazem todas.</p>
            @endif
        </div>
    </details>

    <details class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-950 dark:text-white">Números reservados e não usados ({{ $unused->count() }}{{ $unused->count() >= $unusedLimit ? '+' : '' }})</summary>
        <div class="overflow-x-auto">
            <p class="px-4 pb-2 text-xs text-gray-500 dark:text-gray-400">
                Números reservados há mais de {{ \App\Models\FiscalDocument::UNUSED_NUMBER_AFTER_HOURS }} h que nunca foram usados em uma nota. São candidatos à inutilização na SEFAZ ou na NFE.io (o sistema não inutiliza sozinho).
            </p>
            <table class="w-full divide-y divide-gray-100 text-sm dark:divide-white/10">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2">Nº / série</th>
                        <th class="px-4 py-2">Ambiente</th>
                        <th class="px-4 py-2">Venda</th>
                        <th class="px-4 py-2">Reservado em</th>
                        <th class="px-4 py-2">Situação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($unused as $number)
                        <tr wire:key="unused-{{ $loop->index }}">
                            <td class="px-4 py-2">{{ $number['number'] }} / {{ $number['series'] }}</td>
                            <td class="px-4 py-2">{{ $number['environment'] }}</td>
                            <td class="px-4 py-2">{{ $number['sale'] }}</td>
                            <td class="whitespace-nowrap px-4 py-2">{{ $number['since']->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-2">{{ $number['reason'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-6 text-center text-gray-400">Nenhum número pendente.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </details>

    <p class="text-xs text-gray-400 dark:text-gray-500">
        Só NFC-e autorizadas e ainda válidas (inclui as com cancelamento solicitado ou que falhou), pela data em que o sistema registrou a autorização. Os itens são os que foram enviados na nota: brindes e itens removidos ficam fora; adicionais e frete aparecem em "Adicionais e frete".
    </p>
</x-filament-panels::page>
