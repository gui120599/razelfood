@php
    $withItems = $dimension !== 'payment';
    $money = fn (?int $cents): string => $cents === null ? '—' : 'R$ '.number_format($cents / 100, 2, ',', '.');
@endphp
<table class="w-full divide-y divide-gray-100 text-sm dark:divide-white/10">
    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
        <tr>
            <th class="px-4 py-2">{{ $title }}</th>
            @if ($withItems)
                <th class="px-4 py-2 text-right">Itens</th>
                <th class="px-4 py-2 text-right">Qtd.</th>
                <th class="px-4 py-2 text-right">Valor dos itens</th>
                <th class="px-4 py-2 text-right">Adicionais e frete</th>
                <th class="px-4 py-2 text-right">Desconto</th>
            @endif
            <th class="px-4 py-2 text-right">{{ $withItems ? 'Total' : 'Valor' }}</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
        @forelse ($group['rows'] as $row)
            <tr>
                <td class="px-4 py-2">{{ $row['label'] }}</td>
                @if ($withItems)
                    <td class="px-4 py-2 text-right">{{ $row['items'] }}</td>
                    <td class="px-4 py-2 text-right">{{ $row['quantity'] }}</td>
                    <td class="px-4 py-2 text-right">{{ $money($row['items_cents']) }}</td>
                    <td class="px-4 py-2 text-right">{{ $money($row['others_cents']) }}</td>
                    <td class="px-4 py-2 text-right">{{ $money($row['discount_cents']) }}</td>
                @endif
                <td class="px-4 py-2 text-right">{{ $money($row['total_cents']) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="p-6 text-center text-gray-400">Sem dados no período.</td></tr>
        @endforelse
    </tbody>
    <tfoot class="bg-gray-50 font-semibold dark:bg-white/5">
        <tr>
            <td class="px-4 py-2">TOTAL</td>
            @if ($withItems)
                <td class="px-4 py-2 text-right">{{ $group['total']['items'] }}</td>
                <td class="px-4 py-2 text-right">{{ $group['total']['quantity'] }}</td>
                <td class="px-4 py-2 text-right">{{ $money($group['total']['items_cents']) }}</td>
                <td class="px-4 py-2 text-right">{{ $money($group['total']['others_cents']) }}</td>
                <td class="px-4 py-2 text-right">{{ $money($group['total']['discount_cents']) }}</td>
            @endif
            <td class="px-4 py-2 text-right">{{ $money($group['total']['total_cents']) }}</td>
        </tr>
    </tfoot>
</table>
