<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comanda {{ $order->displayNumber() }} — {{ $tenant?->name }}</title>
    <style>
        @page { margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            width: 72mm;
            margin: 0 auto;
            padding: 3mm 1mm;
            font: 12px/1.35 'Courier New', Courier, monospace;
            color: #000;
            background: #fff;
        }
        .center { text-align: center; }
        .bold { font-weight: 700; }
        .big { font-size: 18px; font-weight: 700; }
        .sm { font-size: 10px; }
        .row { display: flex; justify-content: space-between; gap: 4mm; }
        .mt { margin-top: 2mm; }
        .item { margin-top: 1.5mm; }
        .sub { padding-left: 4mm; }
        hr { border: 0; border-top: 1px dashed #000; margin: 2mm 0; }
        .warn { border: 1px solid #000; padding: 1mm; margin-top: 2mm; font-weight: 700; }
        .no-print {
            margin: 4mm auto;
            display: flex;
            gap: 2mm;
            justify-content: center;
        }
        .no-print button {
            font: inherit;
            padding: 2mm 4mm;
            border: 1px solid #000;
            background: #fff;
            cursor: pointer;
        }
        @media print {
            .no-print { display: none; }
            body { width: auto; padding: 0; }
        }
    </style>
</head>
<body>
    @php
        $money = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    @endphp

    <div class="center">
        <div class="bold">{{ $tenant?->name }}</div>
        @if ($tenant?->whatsapp_number)
            <div class="sm">{{ $tenant->whatsapp_number }}</div>
        @endif
        <div class="sm">{{ $order->created_at?->format('d/m/Y H:i') }}</div>
    </div>

    <hr>

    <div class="center">
        <div class="big">COMANDA {{ $order->displayNumber() }}</div>
        <div class="sm">pedido interno #{{ $order->id }}</div>
        <div class="mt bold">{{ $order->fulfillmentType()->label() }} · {{ $order->origin->label() }}</div>
    </div>

    <hr>

    <div>
        <span class="bold">Cliente:</span> {{ $order->client?->name ?? '—' }}
    </div>
    <div>
        <span class="bold">Telefone:</span> {{ $order->client?->phone ?? '—' }}
    </div>

    @if ($order->requiresDelivery())
        <div class="mt">
            <span class="bold">Entrega:</span> {{ $order->deliveryOption?->name }}
        </div>
        <div>{{ $order->delivery_address ?? '—' }}</div>
        @if ($order->is_unlisted_neighborhood)
            <div class="warn">! BAIRRO FORA DA AREA MAPEADA — confirmar viabilidade antes de aceitar</div>
        @endif
    @elseif ($order->deliveryOption)
        <div class="mt"><span class="bold">Modalidade:</span> {{ $order->deliveryOption->name }}</div>
    @endif

    <hr>

    @foreach ($itemLines as $line)
        <div class="item">
            @if ($line['category_name'])
                <div class="sm bold">{{ mb_strtoupper($line['category_name']) }}</div>
            @endif
            <div class="row">
                <span class="bold">{{ $line['quantity'] }}x {{ $line['name'] }}</span>
                <span>{{ $money($line['line_total']) }}</span>
            </div>
            @foreach ($line['addons_display'] as $addonLine)
                <div class="sub">+ {{ $addonLine }}</div>
            @endforeach
            @if ($line['note'])
                <div class="sub bold">** {{ $line['note'] }}</div>
            @endif
        </div>
    @endforeach

    <hr>

    <div class="row"><span>Subtotal</span><span>{{ $money($order->items_total) }}</span></div>
    @if ($order->discount_total > 0)
        <div class="row"><span>Desconto</span><span>-{{ $money($order->discount_total) }}</span></div>
    @endif
    <div class="row"><span>Entrega</span><span>{{ $money($order->delivery_fee) }}</span></div>
    <div class="row big"><span>TOTAL</span><span>{{ $money($order->grand_total) }}</span></div>

    <hr>

    <div class="bold">Pagamento</div>
    @forelse ($order->payments as $payment)
        <div class="row">
            <span>{{ $payment->payment_option_name }}</span>
            @if ($order->payments->count() > 1)
                <span>{{ $money($payment->amount) }}</span>
            @endif
        </div>
        @if ($payment->change_for)
            <div class="sub">Troco para {{ $money($payment->change_for) }}</div>
            @if ($payment->change_for > $payment->amount)
                <div class="sub bold">Troco: {{ $money($payment->change_for - $payment->amount) }}</div>
            @endif
        @endif
    @empty
        <div>—</div>
    @endforelse

    @if ($order->notes)
        <hr>
        <div class="bold">Observação</div>
        <div>{{ $order->notes }}</div>
    @endif

    <hr>
    <div class="center sm">RazelFood</div>

    <div class="no-print">
        <button type="button" onclick="window.print()">Imprimir</button>
        <button type="button" onclick="window.close()">Fechar</button>
    </div>

    <script>
        window.addEventListener('load', function () { window.print(); });
    </script>
</body>
</html>
