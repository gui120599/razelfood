@php
    $money = fn (?int $cents): string => $cents === null ? '—' : 'R$ '.number_format($cents / 100, 2, ',', '.');
@endphp

<x-reports.print-layout title="Relatório fiscal (NFC-e)" :tenant="$tenant" :period-label="$periodLabel">
    @if ($withoutItems > 0)
        <p class="muted" style="margin-bottom: 8px;"><strong>Atenção:</strong> {{ $withoutItems }} nota(s) do período não têm o detalhe de itens (emitidas antes deste relatório) e ficam fora dos agrupamentos por item.</p>
    @endif

    <div class="group">
        <p class="group-title">Resumo</p>
        <table>
            <tbody>
                <tr><td>Notas autorizadas</td><td class="num">{{ $summary['notes'] }}</td></tr>
                <tr><td>Itens</td><td class="num">{{ $summary['items'] }}</td></tr>
                <tr><td>Valor dos itens</td><td class="num">{{ $money($summary['items_cents']) }}</td></tr>
                <tr><td>Adicionais e frete</td><td class="num">{{ $money($summary['others_cents']) }}</td></tr>
                <tr><td>Descontos</td><td class="num">{{ $money($summary['discount_cents']) }}</td></tr>
            </tbody>
            <tfoot>
                <tr><td>Total das notas</td><td class="num">{{ $money($summary['total_cents']) }}</td></tr>
            </tfoot>
        </table>
    </div>

    @foreach ($groups as $key => $group)
        @php($withItems = $key !== 'payment')
        <div class="group">
            <p class="group-title">{{ $titles[$key] }}</p>
            <table>
                <thead>
                    <tr>
                        <th>{{ $dimensions[$key] }}</th>
                        @if ($withItems)
                            <th class="num">Itens</th>
                            <th class="num">Qtd.</th>
                            <th class="num">Valor dos itens</th>
                            <th class="num">Adicionais e frete</th>
                            <th class="num">Desconto</th>
                        @endif
                        <th class="num">{{ $withItems ? 'Total' : 'Valor' }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($group['rows'] as $row)
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            @if ($withItems)
                                <td class="num">{{ $row['items'] }}</td>
                                <td class="num">{{ $row['quantity'] }}</td>
                                <td class="num">{{ $money($row['items_cents']) }}</td>
                                <td class="num">{{ $money($row['others_cents']) }}</td>
                                <td class="num">{{ $money($row['discount_cents']) }}</td>
                            @endif
                            <td class="num">{{ $money($row['total_cents']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">Sem dados no período.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <td>TOTAL</td>
                        @if ($withItems)
                            <td class="num">{{ $group['total']['items'] }}</td>
                            <td class="num">{{ $group['total']['quantity'] }}</td>
                            <td class="num">{{ $money($group['total']['items_cents']) }}</td>
                            <td class="num">{{ $money($group['total']['others_cents']) }}</td>
                            <td class="num">{{ $money($group['total']['discount_cents']) }}</td>
                        @endif
                        <td class="num">{{ $money($group['total']['total_cents']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endforeach

    <div class="group">
        <p class="group-title">Notas do período ({{ $notes->count() }})</p>
        <table>
            <thead>
                <tr><th>Autorização</th><th>Nº / série</th><th>Venda</th><th>Cliente</th><th class="num">Total</th></tr>
            </thead>
            <tbody>
                @forelse ($notes as $note)
                    <tr>
                        <td>{{ $note['authorized_at']->format('d/m/Y H:i') }}</td>
                        <td>{{ $note['number'] }} / {{ $note['series'] }}</td>
                        <td>{{ $note['sale'] }}</td>
                        <td>{{ $note['client'] }}</td>
                        <td class="num">{{ $money($note['total_cents']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Nenhuma nota autorizada no período.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr><td colspan="4">TOTAL</td><td class="num">{{ $money($notesTotals['total_cents']) }}</td></tr>
            </tfoot>
        </table>
    </div>

    @if ($unused->isNotEmpty())
        <div class="group">
            <p class="group-title">Números reservados e não usados ({{ $unused->count() }})</p>
            <table>
                <thead>
                    <tr><th>Nº / série</th><th>Ambiente</th><th>Venda</th><th>Reservado em</th><th>Situação</th></tr>
                </thead>
                <tbody>
                    @foreach ($unused as $number)
                        <tr>
                            <td>{{ $number['number'] }} / {{ $number['series'] }}</td>
                            <td>{{ $number['environment'] }}</td>
                            <td>{{ $number['sale'] }}</td>
                            <td>{{ $number['since']->format('d/m/Y H:i') }}</td>
                            <td>{{ $number['reason'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <p class="muted" style="margin-top: 10px;">Só NFC-e autorizadas e ainda válidas (inclui as com cancelamento solicitado ou falho), pela data em que o sistema registrou a autorização. Itens como enviados na nota; adicionais e frete em "Adicionais e frete".</p>
</x-reports.print-layout>
