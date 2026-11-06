{{-- Checklist de prontidão fiscal (App\Support\Fiscal\FiscalReadiness): o que falta para emitir NFC-e. --}}
@php
    $titles = [
        \App\Support\Fiscal\FiscalIssue::SECTION_TENANT => 'Estabelecimento',
        \App\Support\Fiscal\FiscalIssue::SECTION_PRODUCTS => 'Produtos',
        \App\Support\Fiscal\FiscalIssue::SECTION_PAYMENTS => 'Formas de pagamento',
    ];
@endphp

@if ($report->ready())
    <p class="text-sm text-success-600 dark:text-success-400">Tudo pronto: o estabelecimento, os produtos e as formas de pagamento têm os dados fiscais necessários.</p>
@else
    <div class="space-y-3 text-sm">
        <p class="text-gray-600 dark:text-gray-300">Falta completar o cadastro abaixo para poder emitir NFC-e:</p>

        @foreach ($titles as $section => $title)
            @php($issues = $report->issuesIn($section))

            @if ($issues !== [])
                <div>
                    <p class="font-semibold">{{ $title }}</p>
                    <ul class="mt-1 list-disc space-y-1 pl-5">
                        @foreach ($issues as $issue)
                            <li>
                                {{ $issue->message }}
                                @if ($issue->items !== [])
                                    <ul class="list-[circle] pl-5 text-gray-500 dark:text-gray-400">
                                        @foreach ($issue->items as $item)
                                            <li>{{ $item }}</li>
                                        @endforeach
                                        @if ($issue->count > count($issue->items))
                                            <li>… e mais {{ $issue->count - count($issue->items) }}.</li>
                                        @endif
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach
    </div>
@endif
