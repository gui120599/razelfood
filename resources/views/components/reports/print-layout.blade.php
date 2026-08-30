@props([
    'title',
    'tenant' => null,
    'periodLabel' => null,
])

<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}{{ $tenant?->name ? ' — '.$tenant->name : '' }}</title>
    <style>
        @page { size: A4; margin: 12mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font: 11px/1.4 Arial, Helvetica, sans-serif; color: #000; background: #fff; }
        h1 { font-size: 15px; }
        .muted { color: #555; }
        .head { border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 10px; }
        .head .row { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #ccc; vertical-align: top; }
        th { border-bottom: 1px solid #000; font-size: 10px; text-transform: uppercase; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tfoot td { border-top: 2px solid #000; border-bottom: 0; font-weight: 700; }
        .group-title { margin-top: 16px; font-size: 13px; font-weight: 700; border-bottom: 1px solid #000; padding-bottom: 3px; }
        .group-summary { margin: 4px 0 2px; }
        .group + .group { page-break-inside: avoid; }
        .no-print { margin: 16px 0; display: flex; gap: 8px; }
        .no-print button { font: inherit; padding: 6px 14px; border: 1px solid #000; background: #fff; cursor: pointer; }
        @media print {
            .no-print { display: none; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="head">
        <div class="row">
            <h1>{{ $title }}</h1>
            <span class="muted">{{ $tenant?->name }}</span>
        </div>
        <div class="row">
            <span class="muted">{{ $periodLabel }}</span>
            <span class="muted">Emitido em {{ now()->format('d/m/Y H:i') }}</span>
        </div>
    </div>

    {{ $slot }}

    <div class="no-print">
        <button type="button" onclick="window.print()">Imprimir</button>
        <button type="button" onclick="window.close()">Fechar</button>
    </div>

    <script>
        window.addEventListener('load', function () { window.print(); });
    </script>
</body>
</html>
