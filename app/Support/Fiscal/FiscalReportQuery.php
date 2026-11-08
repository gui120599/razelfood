<?php

namespace App\Support\Fiscal;

use App\Enums\FiscalDocumentStatus;
use App\Models\FiscalDocument;
use App\Models\SaleOrder;
use App\Models\Scopes\TenantScope;
use App\Support\Sales\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fonte única do Relatório fiscal (página, CSV e impressão): NFC-e AUTORIZADAS do tenant no
 * período (pela data de autorização), com os itens agrupados por NCM, categoria, produto,
 * CFOP/CSOSN, forma de pagamento ou dia. Lê o snapshot `fiscal_document_items` (o que foi
 * enviado à NFE.io), então sempre bate com a nota mesmo que o catálogo mude depois.
 * Consulta sempre pelo tenant informado (não depende do tenant "atual").
 *
 * @phpstan-type Row array{label: string, items: ?int, quantity: ?int, items_cents: ?int, others_cents: ?int, discount_cents: ?int, total_cents: int}
 */
class FiscalReportQuery
{
    public const DIMENSIONS = [
        'ncm' => 'NCM',
        'category' => 'Categoria de produto',
        'product' => 'Produto',
        'cfop_csosn' => 'CFOP / CSOSN',
        'payment' => 'Forma de pagamento',
        'day' => 'Dia de autorização',
    ];

    /** Títulos das tabelas (NCM e CFOP são siglas: sem minúscula forçada). */
    public const TITLES = [
        'ncm' => 'Por NCM',
        'category' => 'Por categoria de produto',
        'product' => 'Por produto',
        'cfop_csosn' => 'Por CFOP / CSOSN',
        'payment' => 'Por forma de pagamento',
        'day' => 'Por dia de autorização',
    ];

    public function __construct(
        private readonly int $tenantId,
        private readonly CarbonInterface $start,
        private readonly CarbonInterface $end,
    ) {}

    /**
     * Monta a consulta a partir de datas cruas (query string/tela): inválidas ou vazias caem no
     * mês corrente, e início depois do fim é invertido.
     */
    public static function forPeriod(int $tenantId, ?string $start, ?string $end): self
    {
        $parse = function (?string $value, CarbonInterface $default): CarbonInterface {
            try {
                return filled($value) ? Carbon::parse($value) : $default;
            } catch (Throwable) {
                return $default;
            }
        };

        $from = $parse($start, now()->startOfMonth());
        $to = $parse($end, now());

        return $from->gt($to) ? new self($tenantId, $to, $from) : new self($tenantId, $from, $to);
    }

    /**
     * Só agrupamentos conhecidos, na ordem canônica.
     *
     * @param  array<int, mixed>  $requested
     * @return list<string>
     */
    public static function dimensionsFrom(array $requested): array
    {
        return array_values(array_intersect(array_keys(self::DIMENSIONS), array_map('strval', $requested)));
    }

    public function start(): CarbonInterface
    {
        return $this->start;
    }

    public function end(): CarbonInterface
    {
        return $this->end;
    }

    /**
     * Notas válidas do período, da mais antiga para a mais nova. `$limit` 0 = todas (CSV e impressão).
     *
     * @return Collection<int, array{authorized_at: CarbonInterface, number: int, series: int, sale: string, client: string, total_cents: int, has_items: bool}>
     */
    public function notes(int $limit = 0): Collection
    {
        $documents = $this->documents()->with('sale')->withExists('items')->orderBy('authorized_at')->orderBy('id')
            ->when($limit > 0, fn ($query) => $query->limit($limit))
            ->get();

        // Cliente = o do primeiro pedido da venda.
        $clients = [];

        SaleOrder::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->whereIn('sale_id', $documents->pluck('sale_id'))
            ->orderBy('id')
            ->get(['sale_id', 'client_name'])
            ->each(function (SaleOrder $order) use (&$clients): void {
                $clients[$order->sale_id] ??= $order->client_name;
            });

        return $documents->map(fn (FiscalDocument $document): array => [
            'authorized_at' => $document->authorized_at,
            'number' => $document->number,
            'series' => $document->series,
            'sale' => $document->sale?->displayNumber() ?? '—',
            'client' => ($clients[$document->sale_id] ?? null) ?: 'Consumidor',
            'total_cents' => Money::toCents($document->sale?->total),
            'has_items' => (bool) $document->items_exists,
        ])->values();
    }

    /**
     * Quantidade e valor total (das vendas) de TODAS as notas válidas do período.
     *
     * @return array{count: int, total_cents: int}
     */
    public function notesTotals(): array
    {
        $row = DB::table('fiscal_documents as d')
            ->join('sales as s', 's.id', '=', 'd.sale_id')
            ->where('d.tenant_id', $this->tenantId)
            ->whereNull('d.deleted_at')
            ->whereIn('d.status', FiscalDocumentStatus::standingValues())
            ->whereBetween('d.authorized_at', [$this->start->copy()->startOfDay(), $this->end->copy()->endOfDay()])
            ->selectRaw('COUNT(d.id) as notes, ROUND(COALESCE(SUM(s.total), 0) * 100) as total_cents')
            ->first();

        return ['count' => (int) $row->notes, 'total_cents' => (int) $row->total_cents];
    }

    /**
     * Notas válidas do período SEM itens gravados (emitidas antes do snapshot): entram na lista de
     * notas e na forma de pagamento, mas não nos agrupamentos por item. A tela avisa.
     */
    public function notesWithoutItems(): int
    {
        return $this->documents()->whereDoesntHave('items', fn ($query) => $query->withoutGlobalScope(TenantScope::class))->count();
    }

    /**
     * @return array{notes: int, items: int, items_cents: int, others_cents: int, discount_cents: int, total_cents: int}
     */
    public function summary(): array
    {
        $row = $this->itemsBase()
            ->selectRaw('COUNT(i.id) as items, ROUND(COALESCE(SUM(i.total_amount), 0) * 100) as items_cents, ROUND(COALESCE(SUM(i.others_amount), 0) * 100) as others_cents, ROUND(COALESCE(SUM(i.discount_amount), 0) * 100) as discount_cents')
            ->first();

        $items = (int) $row->items_cents;
        $others = (int) $row->others_cents;
        $discount = (int) $row->discount_cents;

        return [
            'notes' => $this->notesTotals()['count'],
            'items' => (int) $row->items,
            'items_cents' => $items,
            'others_cents' => $others,
            'discount_cents' => $discount,
            'total_cents' => $items + $others - $discount,
        ];
    }

    /**
     * @return array{rows: Collection<int, Row>, total: Row}
     */
    public function group(string $dimension): array
    {
        $rows = $dimension === 'payment' ? $this->byPayment() : $this->byItem($dimension);

        return ['rows' => $rows, 'total' => $this->total($rows, $dimension === 'payment')];
    }

    /**
     * Números reservados e nunca usados (candidatos à inutilização), de qualquer data. Um envio
     * incerto (timeout/5xx) vem sinalizado: o número pode ter sido autorizado.
     *
     * @return Collection<int, array{number: int, series: int, environment: string, sale: string, since: CarbonInterface, reason: string}>
     */
    public function unusedNumbers(int $limit = 0): Collection
    {
        return FiscalDocument::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->unusedNumbers()
            ->with('sale')
            ->orderBy('created_at')
            ->when($limit > 0, fn ($query) => $query->limit($limit))
            ->get()
            ->map(fn (FiscalDocument $document): array => [
                'number' => $document->number,
                'series' => $document->series,
                'environment' => $document->environment->label(),
                'sale' => $document->sale?->displayNumber() ?? '—',
                'since' => $document->created_at,
                'reason' => $document->isAmbiguousSend()
                    ? 'Envio incerto: a NFE.io pode ter recebido a nota. Confira lá antes de inutilizar.'
                    : $document->status->label().($document->error_message ? ': '.$document->error_message : ''),
            ])
            ->values();
    }

    /**
     * Linhas para o CSV: resumo + uma tabela por agrupamento pedido.
     *
     * @param  list<string>  $dimensions
     * @return list<list<string|int>>
     */
    public function toCsv(array $dimensions): array
    {
        $number = fn (int $cents): string => number_format($cents / 100, 2, ',', '');
        $summary = $this->summary();

        $lines = [
            ['Relatório fiscal — NFC-e autorizadas de '.$this->start->format('d/m/Y').' a '.$this->end->format('d/m/Y')],
            [],
            ['Resumo'],
            ['Notas autorizadas', $summary['notes']],
            ['Itens', $summary['items']],
            ['Valor dos itens', $number($summary['items_cents'])],
            ['Adicionais e frete', $number($summary['others_cents'])],
            ['Descontos', $number($summary['discount_cents'])],
            ['Total das notas', $number($summary['total_cents'])],
        ];

        if (($legacy = $this->notesWithoutItems()) > 0) {
            $lines[] = ["Atenção: {$legacy} nota(s) do período sem detalhe de itens (emitidas antes do relatório) ficam fora dos agrupamentos por item."];
        }

        foreach ($dimensions as $dimension) {
            if (! isset(self::DIMENSIONS[$dimension])) {
                continue;
            }

            $group = $this->group($dimension);
            $lines[] = [];
            $lines[] = [self::TITLES[$dimension]];
            $lines[] = [self::DIMENSIONS[$dimension], 'Itens', 'Quantidade', 'Valor dos itens', 'Adicionais e frete', 'Desconto', 'Total'];

            foreach ($group['rows']->push($group['total']) as $row) {
                $lines[] = [
                    $row['label'],
                    $row['items'] ?? '',
                    $row['quantity'] ?? '',
                    $row['items_cents'] === null ? '' : $number($row['items_cents']),
                    $row['others_cents'] === null ? '' : $number($row['others_cents']),
                    $row['discount_cents'] === null ? '' : $number($row['discount_cents']),
                    $number($row['total_cents']),
                ];
            }
        }

        return $lines;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<FiscalDocument>
     */
    private function documents()
    {
        return FiscalDocument::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->whereIn('status', FiscalDocumentStatus::standingValues())
            ->whereBetween('authorized_at', [$this->start->copy()->startOfDay(), $this->end->copy()->endOfDay()]);
    }

    /**
     * Itens (alias `i`) das notas autorizadas (alias `d`) do período, do tenant.
     */
    private function itemsBase(): Builder
    {
        return DB::table('fiscal_document_items as i')
            ->join('fiscal_documents as d', 'd.id', '=', 'i.fiscal_document_id')
            ->where('i.tenant_id', $this->tenantId)
            ->where('d.tenant_id', $this->tenantId)
            ->whereNull('d.deleted_at')
            ->whereIn('d.status', FiscalDocumentStatus::standingValues())
            ->whereBetween('d.authorized_at', [$this->start->copy()->startOfDay(), $this->end->copy()->endOfDay()]);
    }

    /**
     * @return Collection<int, Row>
     */
    private function byItem(string $dimension): Collection
    {
        $label = match ($dimension) {
            'ncm' => "COALESCE(NULLIF(i.ncm, ''), 'Sem NCM')",
            'product' => "COALESCE(NULLIF(i.product_name, ''), i.description)",
            'cfop_csosn' => "CONCAT('CFOP ', COALESCE(NULLIF(i.cfop, ''), '—'), ' / CSOSN ', COALESCE(NULLIF(i.csosn, ''), '—'))",
            'day' => "DATE_FORMAT(d.authorized_at, '%Y-%m-%d')",
            default => "COALESCE(NULLIF(i.category_name, ''), 'Sem categoria')",
        };

        return $this->itemsBase()
            ->selectRaw("{$label} as label, COUNT(i.id) as items, COALESCE(SUM(i.quantity), 0) as quantity, ROUND(COALESCE(SUM(i.total_amount), 0) * 100) as items_cents, ROUND(COALESCE(SUM(i.others_amount), 0) * 100) as others_cents, ROUND(COALESCE(SUM(i.discount_amount), 0) * 100) as discount_cents")
            ->groupBy(DB::raw($label))
            ->orderByRaw(in_array($dimension, ['day', 'ncm'], true) ? 'label' : '(items_cents + others_cents - discount_cents) DESC')
            ->get()
            ->map(fn (object $row): array => [
                'label' => $dimension === 'day' ? date('d/m/Y', strtotime((string) $row->label)) : (string) $row->label,
                'items' => (int) $row->items,
                'quantity' => (int) $row->quantity,
                'items_cents' => (int) $row->items_cents,
                'others_cents' => (int) $row->others_cents,
                'discount_cents' => (int) $row->discount_cents,
                'total_cents' => (int) $row->items_cents + (int) $row->others_cents - (int) $row->discount_cents,
            ])
            ->values();
    }

    /**
     * Forma de pagamento sai dos pagamentos da venda (recebido menos o troco), não dos itens.
     *
     * @return Collection<int, Row>
     */
    private function byPayment(): Collection
    {
        return DB::table('sale_payments as p')
            ->join('fiscal_documents as d', 'd.sale_id', '=', 'p.sale_id')
            ->where('p.tenant_id', $this->tenantId)
            ->where('d.tenant_id', $this->tenantId)
            ->whereNull('d.deleted_at')
            ->whereIn('d.status', FiscalDocumentStatus::standingValues())
            ->whereBetween('d.authorized_at', [$this->start->copy()->startOfDay(), $this->end->copy()->endOfDay()])
            ->selectRaw('p.payment_option_name as label, ROUND(SUM(p.amount - p.change_amount) * 100) as total_cents')
            ->groupBy('p.payment_option_name')
            ->orderByDesc('total_cents')
            ->get()
            ->map(fn (object $row): array => [
                'label' => (string) $row->label,
                'items' => null,
                'quantity' => null,
                'items_cents' => null,
                'others_cents' => null,
                'discount_cents' => null,
                'total_cents' => (int) $row->total_cents,
            ])
            ->values();
    }

    /**
     * @param  Collection<int, Row>  $rows
     * @return Row
     */
    private function total(Collection $rows, bool $paymentOnly): array
    {
        return [
            'label' => 'TOTAL',
            'items' => $paymentOnly ? null : (int) $rows->sum('items'),
            'quantity' => $paymentOnly ? null : (int) $rows->sum('quantity'),
            'items_cents' => $paymentOnly ? null : (int) $rows->sum('items_cents'),
            'others_cents' => $paymentOnly ? null : (int) $rows->sum('others_cents'),
            'discount_cents' => $paymentOnly ? null : (int) $rows->sum('discount_cents'),
            'total_cents' => (int) $rows->sum('total_cents'),
        ];
    }
}
