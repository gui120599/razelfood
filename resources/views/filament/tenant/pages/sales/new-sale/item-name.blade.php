{{--
    Nome de um item (avulso ou de pedido) na Nova Venda: categoria como badge e, para pizza de
    vários sabores, um sabor por linha com a fração; adicionais abaixo, um por linha.
    Mesmo markup do componente item-nome do Pizzaria-App.
--}}
<span class="{{ $class ?? '' }}">
    @if ($line['category'])
        <span class="inline-block rounded border border-teal-200 bg-teal-50 px-1.5 py-0.5 align-middle text-[10px] font-semibold uppercase tracking-wide text-teal-700 dark:border-teal-500/20 dark:bg-teal-500/10 dark:text-teal-400">{{ $line['category'] }}</span>
    @endif

    @if ($line['flavors'] !== [])
        @foreach ($line['flavors'] as $flavorLine)
            <span class="block">{{ $flavorLine }}</span>
        @endforeach
    @else
        {{ $line['name'] }}
    @endif

    @foreach ($line['details'] as $detail)
        <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $detail }}</span>
    @endforeach
</span>
