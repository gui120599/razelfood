<?php

namespace App\Filament\Tenant\Widgets\Reports;

use App\Enums\OrderStatus;
use App\Filament\Tenant\Widgets\Reports\Concerns\ResolvesReportPeriod;
use App\Models\OrderItem;
use App\Models\Product;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Mais vendidos no período (RN-15 — calculado, não editado). Agrega
 * `order_items` em PHP para ratear combos pela mesma fração dos sabores
 * (1 pizza meio a meio = 0,5 pra cada sabor), consistente com o rateio de
 * `products.sales_count`. Não usa `sales_count` porque aquele é acumulado
 * total, não recortável por período.
 */
class TopProductsTable extends Widget
{
    use InteractsWithPageFilters;
    use ResolvesReportPeriod;

    protected string $view = 'filament.tenant.widgets.reports.top-products-table';

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    private const LIMIT = 15;

    /**
     * @return array<int, array{name: string, quantity: float, revenue: float}>
     */
    public function topProducts(): array
    {
        [$start, $end] = $this->reportRange();

        $items = OrderItem::query()
            ->whereHas('order', fn ($query) => $query
                ->openedBetween($start, $end)
                ->where('status', '!=', OrderStatus::Cancelled->value))
            ->get(['product_id', 'quantity', 'unit_price', 'flavors']);

        $totals = [];

        foreach ($items as $item) {
            $targets = ! empty($item->flavors) ? $item->flavors : [$item->product_id];
            $share = $item->quantity / count($targets);
            $revenueShare = ((float) $item->unit_price * $item->quantity) / count($targets);

            foreach ($targets as $productId) {
                $totals[$productId]['quantity'] = ($totals[$productId]['quantity'] ?? 0) + $share;
                $totals[$productId]['revenue'] = ($totals[$productId]['revenue'] ?? 0) + $revenueShare;
            }
        }

        if ($totals === []) {
            return [];
        }

        $names = Product::withTrashed()->whereIn('id', array_keys($totals))->pluck('name', 'id');

        return collect($totals)
            ->map(fn (array $row, int $productId): array => [
                'name' => $names[$productId] ?? 'Produto removido',
                'quantity' => round($row['quantity'], 2),
                'revenue' => round($row['revenue'], 2),
            ])
            ->sortByDesc('quantity')
            ->take(self::LIMIT)
            ->values()
            ->all();
    }
}
