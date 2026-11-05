{{-- Resumo da tela "Fechar conta": pedidos, itens avulsos e situação do pagamento (preview; a regra que vale é a de FinalizeSale). --}}
<div class="space-y-4 text-sm">
    @if ($orders->isNotEmpty())
        <div>
            <p class="mb-1 font-semibold">Pedidos</p>
            <ul class="divide-y divide-gray-200 rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10">
                @foreach ($orders as $order)
                    <li class="flex items-center justify-between gap-3 px-3 py-2">
                        <span>
                            <span class="font-medium">{{ $order['number'] }}</span>
                            <span class="text-gray-500 dark:text-gray-400">· {{ $order['client'] }} · {{ $order['status'] }}</span>
                        </span>
                        <span class="font-medium">{{ $order['total'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($standalone->isNotEmpty())
        <div>
            <p class="mb-1 font-semibold">Itens avulsos</p>
            <ul class="divide-y divide-gray-200 rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10">
                @foreach ($standalone as $line)
                    <li class="flex items-center justify-between gap-3 px-3 py-2">
                        <span>{{ $line['quantity'] }}× {{ $line['name'] }} <span class="text-gray-500 dark:text-gray-400">({{ $line['unit'] }})</span></span>
                        <span class="font-medium">{{ $line['total'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($orders->isEmpty() && $standalone->isEmpty())
        <p class="text-gray-500 dark:text-gray-400">Nenhum pedido selecionado. Adicione itens avulsos abaixo ou volte para "Contas a fechar".</p>
    @endif

    <dl class="grid grid-cols-2 gap-x-4 gap-y-1 rounded-lg border border-gray-200 p-3 dark:border-white/10 sm:grid-cols-4">
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Total da conta</dt>
            <dd class="text-base font-semibold">{{ $total }}</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Recebido</dt>
            <dd class="text-base font-semibold">{{ $received }}</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Falta receber</dt>
            <dd class="text-base font-semibold {{ $remainingCents > 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $remaining }}</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Troco</dt>
            <dd class="text-base font-semibold">{{ $change }}</dd>
        </div>
    </dl>

    @if ($changeBlocked)
        <p class="text-danger-600 dark:text-danger-400">O valor recebido passa do total, mas o troco só pode sair de pagamento em dinheiro.</p>
    @elseif ($covered)
        <p class="text-success-600 dark:text-success-400">Conta coberta. Confira os valores e finalize a venda.</p>
    @endif
</div>
