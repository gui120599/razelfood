<?php

namespace Tests\Feature\Reports;

use App\Enums\CancellationReason;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Widgets\Reports\CancellationReasonsChart;
use App\Filament\Tenant\Widgets\Reports\OrdersByOriginChart;
use App\Filament\Tenant\Widgets\Reports\OrdersByStatusChart;
use App\Filament\Tenant\Widgets\Reports\PaymentMethodsChart;
use App\Filament\Tenant\Widgets\Reports\ReportsOverview;
use App\Filament\Tenant\Widgets\Reports\TopProductsTable;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class ReportsWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private array $filters;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);
        CurrentTenant::set($this->tenant);

        $this->filters = [
            'startDate' => now()->subDays(10)->toDateString(),
            'endDate' => now()->toDateString(),
        ];
    }

    private function makeOrder(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'items_total' => 50, 'discount_total' => 0, 'delivery_fee' => 0, 'grand_total' => 50,
            'status' => OrderStatus::Finished,
            'origin' => OrderOrigin::Menu,
            'opened_at' => now()->subDays(2),
        ], $attributes));
    }

    private function widgetData(string $widgetClass): array
    {
        $instance = Livewire::test($widgetClass, ['pageFilters' => $this->filters])->instance();
        $method = new ReflectionMethod($instance, method_exists($instance, 'getData') ? 'getData' : 'getStats');
        $method->setAccessible(true);

        return $method->invoke($instance);
    }

    public function test_overview_counts_revenue_ticket_and_cancellations(): void
    {
        $this->makeOrder(['grand_total' => 100, 'status' => OrderStatus::Finished]);
        $this->makeOrder(['grand_total' => 60, 'status' => OrderStatus::Delivered]);
        $this->makeOrder(['grand_total' => 40, 'status' => OrderStatus::Cancelled, 'cancellation_reason' => CancellationReason::Delay]);
        $this->makeOrder(['is_unlisted_neighborhood' => true]);

        $stats = collect($this->widgetData(ReportsOverview::class))
            ->mapWithKeys(fn ($stat) => [$stat->getLabel() => $stat->getValue()]);

        $this->assertSame('4', (string) $stats['Pedidos no período']);
        $this->assertStringContainsString('210,00', $stats['Faturamento']); // 100 + 60 + 50 (exclui os 40 do cancelado)
        $this->assertStringContainsString('1 (25%)', $stats['Cancelamentos']);
        $this->assertSame(1, $stats['Fora da área mapeada']);
    }

    public function test_orders_by_status_chart_groups_counts(): void
    {
        $this->makeOrder(['status' => OrderStatus::Finished]);
        $this->makeOrder(['status' => OrderStatus::Finished]);
        $this->makeOrder(['status' => OrderStatus::Cancelled, 'cancellation_reason' => CancellationReason::Other]);

        $data = $this->widgetData(OrdersByStatusChart::class);

        $byLabel = array_combine($data['labels'], $data['datasets'][0]['data']);
        $this->assertSame(2, $byLabel['Finalizado']);
        $this->assertSame(1, $byLabel['Cancelado']);
    }

    public function test_orders_by_origin_chart_groups_counts(): void
    {
        $this->makeOrder(['origin' => OrderOrigin::Menu]);
        $this->makeOrder(['origin' => OrderOrigin::Staff]);
        $this->makeOrder(['origin' => OrderOrigin::Staff]);

        $data = $this->widgetData(OrdersByOriginChart::class);
        $byLabel = array_combine($data['labels'], $data['datasets'][0]['data']);

        $this->assertSame(1, $byLabel['Cardápio']);
        $this->assertSame(2, $byLabel['Atendente']);
    }

    public function test_cancellation_reasons_chart_only_counts_cancelled(): void
    {
        $this->makeOrder(['status' => OrderStatus::Finished]);
        $this->makeOrder(['status' => OrderStatus::Cancelled, 'cancellation_reason' => CancellationReason::Delay]);
        $this->makeOrder(['status' => OrderStatus::Cancelled, 'cancellation_reason' => CancellationReason::Delay]);
        $this->makeOrder(['status' => OrderStatus::Cancelled, 'cancellation_reason' => CancellationReason::DuplicateTest]);

        $data = $this->widgetData(CancellationReasonsChart::class);
        $byLabel = array_combine($data['labels'], $data['datasets'][0]['data']);

        $this->assertSame(2, $byLabel[CancellationReason::Delay->label()]);
        $this->assertSame(1, $byLabel[CancellationReason::DuplicateTest->label()]);
    }

    public function test_payment_methods_chart_sums_amount_excluding_cancelled(): void
    {
        $paid = $this->makeOrder(['grand_total' => 30]);
        OrderPayment::create(['order_id' => $paid->id, 'payment_option_name' => 'Pix', 'is_cash' => false, 'amount' => 30]);

        $cancelled = $this->makeOrder(['grand_total' => 99, 'status' => OrderStatus::Cancelled, 'cancellation_reason' => CancellationReason::Other]);
        OrderPayment::create(['order_id' => $cancelled->id, 'payment_option_name' => 'Pix', 'is_cash' => false, 'amount' => 99]);

        $data = $this->widgetData(PaymentMethodsChart::class);

        $this->assertSame(['Pix (1)'], $data['labels']);
        $this->assertSame([30.0], $data['datasets'][0]['data']);
    }

    public function test_period_filter_excludes_orders_outside_range(): void
    {
        $this->makeOrder(['opened_at' => now()->subDays(2)]);
        $this->makeOrder(['opened_at' => now()->subDays(90)]);

        $stats = collect($this->widgetData(ReportsOverview::class))
            ->mapWithKeys(fn ($stat) => [$stat->getLabel() => $stat->getValue()]);

        $this->assertSame('1', (string) $stats['Pedidos no período']);
    }

    public function test_top_products_prorates_combo_flavors(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $calabresa = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $marguerita = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);

        $order = $this->makeOrder();
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $calabresa->id, 'quantity' => 1,
            'unit_price' => 45, 'original_unit_price' => 45,
            'flavors' => [$calabresa->id, $marguerita->id], 'addons_total' => 0,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $calabresa->id, 'quantity' => 2,
            'unit_price' => 40, 'original_unit_price' => 40, 'addons_total' => 0,
        ]);

        $rows = collect(Livewire::test(TopProductsTable::class, ['pageFilters' => $this->filters])->instance()->topProducts())
            ->mapWithKeys(fn ($row) => [$row['name'] => $row['quantity']]);

        $this->assertSame(2.5, $rows['Calabresa']); // 0,5 do combo + 2 do item simples
        $this->assertSame(0.5, $rows['Marguerita']);
    }

    public function test_widgets_are_isolated_between_tenants(): void
    {
        $this->makeOrder(['grand_total' => 100]);

        $other = Tenant::create(['name' => 'Outro', 'slug' => 'outro', 'status' => TenantStatus::Active, 'whatsapp_number' => '5511900000000']);
        CurrentTenant::set($other);

        $stats = collect($this->widgetData(ReportsOverview::class))
            ->mapWithKeys(fn ($stat) => [$stat->getLabel() => $stat->getValue()]);

        $this->assertSame('0', (string) $stats['Pedidos no período']);
    }
}
