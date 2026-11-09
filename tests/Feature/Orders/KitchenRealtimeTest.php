<?php

namespace Tests\Feature\Orders;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Pages\Kitchen;
use App\Models\BusinessHour;
use App\Models\Category;
use App\Models\Client;
use App\Models\Feature;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Fase 1.S.2c: poll com assinatura e skipRender, alerta de novo pedido, comanda ao iniciar o preparo e os
 * PDFs do cabeçalho. Relógio fixo em 2026-11-09 12:00 (sem horário cadastrado: o turno começa à 00:00).
 */
class KitchenRealtimeTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-09 12:00:00');

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
            'plan_id' => $this->planWithAllFeatures()->id,
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(SeedDefaultTenantRoles::class)($this->tenant);

        $this->actingAsRole('Gerente');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function makeOrder(array $overrides = [], ?Tenant $tenant = null): Order
    {
        $tenant ??= $this->tenant;
        $client = Client::create(['tenant_id' => $tenant->id, 'name' => 'Cliente', 'phone' => fake()->unique()->numerify('119########')]);

        return Order::create(array_merge([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'origin' => OrderOrigin::Menu,
            'items_total' => 40,
            'grand_total' => 40,
            'status' => OrderStatus::Open,
            'opened_at' => now()->subHour(),
        ], $overrides));
    }

    private function addItem(Order $order): void
    {
        $category = Category::firstOrCreate(['tenant_id' => $order->tenant_id, 'name' => 'Pizzas'], ['display_order' => 1]);
        $product = Product::create(['tenant_id' => $order->tenant_id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 40, 'original_unit_price' => 40, 'addons_total' => 0]);
    }

    /** Consultas de card (`order_items`) executadas por uma chamada: um render completo sempre as faz. */
    private function cardQueriesDuring(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'order_items'))->count();
        DB::disableQueryLog();

        return $count;
    }

    // ─── Poll com assinatura ─────────────────────────────────────────────────────────────────

    public function test_a_poll_without_changes_does_not_render_the_board_again(): void
    {
        $order = $this->makeOrder();
        $this->addItem($order);

        $component = Livewire::test(Kitchen::class);

        $this->assertSame(0, $this->cardQueriesDuring(fn () => $component->call('refreshBoard')));
    }

    public function test_a_status_change_makes_the_next_poll_render(): void
    {
        $order = $this->makeOrder();
        $this->addItem($order);

        $component = Livewire::test(Kitchen::class);
        $signature = $component->get('boardSignature');

        Carbon::setTestNow('2026-11-09 12:00:05');
        $order->update(['status' => OrderStatus::Preparing, 'preparing_at' => now()]);

        $this->assertGreaterThan(0, $this->cardQueriesDuring(fn () => $component->call('refreshBoard')));
        $this->assertNotSame($signature, $component->get('boardSignature'));
        $component->assertSeeHtml('id="coluna-preparing"');
    }

    public function test_a_transition_written_without_updated_at_is_still_detected_by_the_count(): void
    {
        $order = $this->makeOrder();
        $this->addItem($order);

        $component = Livewire::test(Kitchen::class);

        DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::Ready->value]);

        $this->assertGreaterThan(0, $this->cardQueriesDuring(fn () => $component->call('refreshBoard')));
    }

    public function test_the_board_redraws_once_a_minute_so_the_time_in_stage_keeps_moving(): void
    {
        $order = $this->makeOrder();
        $this->addItem($order);

        $component = Livewire::test(Kitchen::class);

        Carbon::setTestNow('2026-11-09 12:00:40');
        $this->assertSame(0, $this->cardQueriesDuring(fn () => $component->call('refreshBoard')));

        Carbon::setTestNow('2026-11-09 12:01:05');
        $this->assertGreaterThan(0, $this->cardQueriesDuring(fn () => $component->call('refreshBoard')));
    }

    public function test_the_board_redraws_when_the_shift_changes_without_any_status_change(): void
    {
        $order = $this->makeOrder(['opened_at' => Carbon::parse('2026-11-09 11:30:00')]);
        $this->addItem($order);

        $component = Livewire::test(Kitchen::class);

        $signature = $component->get('boardSignature');

        // O dia virou: o mesmo pedido agora é de um turno anterior.
        Carbon::setTestNow('2026-11-10 00:00:30');
        $component->call('refreshBoard');

        $this->assertNotSame($signature, $component->get('boardSignature'));
        $this->assertSame(1, $component->instance()->outOfShiftTotal());
        $this->assertSame([], $component->instance()->ordersByStatus()->get(OrderStatus::Open->value, collect())->all());
    }

    public function test_changes_in_another_tenant_never_touch_the_signature_or_the_alert(): void
    {
        $component = Livewire::test(Kitchen::class);

        $other = Tenant::create(['name' => 'Outro', 'slug' => 'outro', 'status' => TenantStatus::Active, 'whatsapp_number' => '5511888888888']);
        $this->makeOrder(['status' => OrderStatus::Started], $other);

        $this->assertSame(0, $this->cardQueriesDuring(fn () => $component->call('refreshBoard')));
        $component->assertNotDispatched('novo-pedido');
    }

    // ─── Alerta de novo pedido ───────────────────────────────────────────────────────────────

    public function test_a_new_order_in_the_queue_fires_the_alert_even_when_the_queue_was_empty(): void
    {
        $component = Livewire::test(Kitchen::class)->assertSet('latestIncomingId', 0);

        Carbon::setTestNow('2026-11-09 12:00:05');
        $order = $this->makeOrder(['status' => OrderStatus::Started]);

        $component->call('refreshBoard')->assertDispatched('novo-pedido')->assertSet('latestIncomingId', $order->id);
    }

    public function test_the_alert_does_not_fire_when_the_queue_only_shrinks(): void
    {
        $first = $this->makeOrder(['status' => OrderStatus::Started]);
        $second = $this->makeOrder(['status' => OrderStatus::Started]);

        $component = Livewire::test(Kitchen::class)->assertSet('latestIncomingId', $second->id);

        Carbon::setTestNow('2026-11-09 12:00:05');
        $first->update(['status' => OrderStatus::Open, 'accepted_at' => now()]);

        $component->call('refreshBoard')->assertNotDispatched('novo-pedido')->assertSet('latestIncomingId', $second->id);
    }

    public function test_the_alert_is_not_lost_when_a_local_action_shrinks_the_queue_before_a_new_order_arrives(): void
    {
        $first = $this->makeOrder(['status' => OrderStatus::Started]);
        $this->addItem($first);
        $this->makeOrder(['status' => OrderStatus::Started]);

        $component = Livewire::test(Kitchen::class);

        // O operador confirma um pedido neste tablet e, no mesmo intervalo do poll, chega outro: a fila continua com 2.
        $component->callAction(TestAction::make('advance')->arguments(['order' => $first->id]));
        $arrived = $this->makeOrder(['status' => OrderStatus::Started]);

        Carbon::setTestNow('2026-11-09 12:00:05');
        $component->call('refreshBoard')->assertDispatched('novo-pedido')->assertSet('latestIncomingId', $arrived->id);
    }

    public function test_orders_typed_by_an_attendant_do_not_beep(): void
    {
        $component = Livewire::test(Kitchen::class);

        Carbon::setTestNow('2026-11-09 12:00:05');
        $this->makeOrder(['status' => OrderStatus::Started, 'origin' => OrderOrigin::Staff]);

        $component->call('refreshBoard')->assertNotDispatched('novo-pedido')->assertSet('latestIncomingId', 0);
    }

    // ─── Comanda ao iniciar o preparo ────────────────────────────────────────────────────────

    public function test_starting_the_preparation_asks_the_browser_to_print_the_ticket(): void
    {
        $order = $this->makeOrder(['status' => OrderStatus::Open]);
        $this->addItem($order);

        Livewire::test(Kitchen::class)
            ->callAction(TestAction::make('advance')->arguments(['order' => $order->id]))
            ->assertDispatched('imprimir-pedido', url: route('order.ticket', ['order' => $order->id]));

        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);
    }

    public function test_other_transitions_do_not_ask_for_printing(): void
    {
        $started = $this->makeOrder(['status' => OrderStatus::Started]);
        $this->addItem($started);
        $preparing = $this->makeOrder(['status' => OrderStatus::Preparing]);
        $this->addItem($preparing);

        Livewire::test(Kitchen::class)
            ->callAction(TestAction::make('advance')->arguments(['order' => $started->id]))
            ->assertNotDispatched('imprimir-pedido')
            ->callAction(TestAction::make('advance')->arguments(['order' => $preparing->id]))
            ->assertNotDispatched('imprimir-pedido');
    }

    public function test_a_blocked_transition_does_not_ask_for_printing(): void
    {
        $cancelled = $this->makeOrder(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);
        $this->addItem($cancelled);

        Livewire::test(Kitchen::class)
            ->assertActionHidden(TestAction::make('advance')->arguments(['order' => $cancelled->id]))
            ->assertNotDispatched('imprimir-pedido');
    }

    public function test_the_page_exposes_the_device_preferences_for_sound_and_printing(): void
    {
        Livewire::test(Kitchen::class)
            ->assertSee('Ativar som')
            ->assertSee('Imprimir ao iniciar o preparo')
            ->assertSeeHtml('wire:poll.10s.visible="refreshBoard"')
            ->assertSeeHtml('@novo-pedido.window="alertar()"')
            ->assertSeeHtml('@imprimir-pedido.window="imprimirPedido($event.detail)"');

        // O conteúdo de @script não vai no HTML do componente: confere o código-fonte da view.
        $source = file_get_contents(resource_path('views/filament/tenant/pages/kitchen.blade.php'));
        $this->assertStringContainsString("Alpine.data('centralPedidos'", $source);
        $this->assertStringContainsString("localStorage.getItem('central-pedidos-som')", $source);
        $this->assertStringContainsString("localStorage.getItem('central-pedidos-imprimir-preparo')", $source);
    }

    // ─── PDFs do cabeçalho ───────────────────────────────────────────────────────────────────

    public function test_header_pdfs_point_to_the_existing_report_prints_for_the_shift_day(): void
    {
        $component = Livewire::test(Kitchen::class)
            ->assertActionVisible('pdfDay')
            ->assertActionVisible('pdfDeliveries')
            ->assertSee('PDF do dia')
            ->assertSee('PDF de entregas');

        $component->assertActionHasUrl('pdfDay', route('reports.orders.print', ['shift' => '2026-11-09']))
            ->assertActionShouldOpenUrlInNewTab('pdfDay')
            ->assertActionHasUrl('pdfDeliveries', route('reports.deliveries.print', ['shift' => '2026-11-09']));

        // Com outro turno escolhido, os PDFs seguem a data.
        $component->set('finishedDate', '2026-11-05')
            ->assertActionHasUrl('pdfDay', route('reports.orders.print', ['shift' => '2026-11-05']));
    }

    public function test_header_pdfs_are_hidden_without_the_report_permissions(): void
    {
        $this->actingAsRole('Entregador');

        Livewire::test(Kitchen::class)
            ->assertActionHidden('pdfDay')
            ->assertActionHidden('pdfDeliveries');
    }

    public function test_header_pdfs_are_hidden_when_the_plan_has_no_reports_feature(): void
    {
        // Plano só com a Central de Pedidos (sem a feature de relatórios).
        $plan = Plan::create(['name' => 'Sem relatórios (teste)', 'slug' => 'sem-relatorios-'.uniqid()]);
        $plan->features()->attach(Feature::firstOrCreate(['key' => FeatureKey::CENTRAL_DE_PEDIDOS], ['name' => FeatureKey::CENTRAL_DE_PEDIDOS, 'is_available' => true]));
        $this->tenant->update(['plan_id' => $plan->id]);
        CurrentTenant::set($this->tenant->fresh());

        Livewire::test(Kitchen::class)
            ->assertActionHidden('pdfDay')
            ->assertActionHidden('pdfDeliveries');
    }

    public function test_a_forged_shift_date_falls_back_to_the_current_shift_in_the_pdf_links(): void
    {
        Livewire::test(Kitchen::class)
            ->set('finishedDate', 'next monday')
            ->assertActionHasUrl('pdfDay', route('reports.orders.print', ['shift' => '2026-11-09']));
    }

    public function test_the_signature_ignores_the_finished_history_before_the_current_shift(): void
    {
        $old = $this->makeOrder(['status' => OrderStatus::Finished, 'finished_at' => now()->subDays(10), 'opened_at' => now()->subDays(10)]);
        $this->addItem($old);

        $component = Livewire::test(Kitchen::class);
        $signature = $component->get('boardSignature');

        // Mexer em um pedido finalizado há dias não custa render nem muda a assinatura.
        DB::table('orders')->where('id', $old->id)->update(['updated_at' => now()->addMinute()]);
        $component->call('refreshBoard');

        $this->assertSame($signature, $component->get('boardSignature'));
    }

    public function test_the_signature_is_computed_after_the_saved_preferences_are_loaded(): void
    {
        $cancelled = $this->makeOrder(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);
        $this->addItem($cancelled);

        $first = Livewire::test(Kitchen::class)->set('showCancelled', true);

        // Nova abertura da tela: showCancelled vem salvo e já entra na assinatura inicial (sem render extra no 1º poll).
        $reopened = Livewire::test(Kitchen::class)->assertSet('showCancelled', true);
        $this->assertSame(0, $this->cardQueriesDuring(fn () => $reopened->call('refreshBoard')));
        $this->assertNotNull($first);
    }

    // ─── Relatórios por turno (?shift=) ──────────────────────────────────────────────────────

    public function test_the_orders_print_cuts_by_shift_window_even_across_midnight(): void
    {
        // Segunda-feira abre às 18:00; terça sem horário: o turno vai das 18:00 de segunda até as 18:00 de terça.
        BusinessHour::create(['tenant_id' => $this->tenant->id, 'weekday' => 1, 'opens_at' => '18:00:00', 'closes_at' => '02:00:00', 'is_active' => true]);

        $before = $this->makeOrder(['opened_at' => Carbon::parse('2026-11-09 17:00')]);
        $evening = $this->makeOrder(['opened_at' => Carbon::parse('2026-11-09 19:00')]);
        $afterMidnight = $this->makeOrder(['opened_at' => Carbon::parse('2026-11-10 01:00')]);
        $nextEvening = $this->makeOrder(['opened_at' => Carbon::parse('2026-11-10 18:30')]);

        $response = $this->get(route('reports.orders.print', ['shift' => '2026-11-09']))->assertOk();

        $ids = $response->viewData('orders')->pluck('id')->sort()->values()->all();
        $this->assertSame([$evening->id, $afterMidnight->id], $ids);
        $this->assertStringContainsString('Turno de 09/11/2026', $response->viewData('periodLabel'));
        $this->assertNotContains($before->id, $ids);
        $this->assertNotContains($nextEvening->id, $ids);
    }

    public function test_the_orders_print_keeps_the_calendar_period_without_the_shift_parameter(): void
    {
        $order = $this->makeOrder(['opened_at' => Carbon::parse('2026-11-09 10:00')]);

        $response = $this->get(route('reports.orders.print', ['start' => '2026-11-09', 'end' => '2026-11-09']))->assertOk();

        $this->assertSame([$order->id], $response->viewData('orders')->pluck('id')->all());
        $this->assertStringContainsString('Período: 09/11/2026 a 09/11/2026', $response->viewData('periodLabel'));
    }

    public function test_a_forged_shift_parameter_falls_back_to_the_default_period(): void
    {
        $this->get(route('reports.orders.print', ['shift' => '+99999999 years']))
            ->assertOk()
            ->assertViewHas('periodLabel', fn (string $label): bool => str_starts_with($label, 'Período:'));
    }

    public function test_the_deliveries_print_cuts_by_shift_window(): void
    {
        $courier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $courier->assignRole('Entregador');
        $inside = $this->makeOrder(['status' => OrderStatus::Delivered, 'assigned_delivery_user_id' => $courier->id, 'delivered_at' => Carbon::parse('2026-11-09 10:00'), 'opened_at' => Carbon::parse('2026-11-09 09:00')]);
        $outside = $this->makeOrder(['status' => OrderStatus::Delivered, 'assigned_delivery_user_id' => $courier->id, 'delivered_at' => Carbon::parse('2026-11-10 10:00'), 'opened_at' => Carbon::parse('2026-11-10 09:00')]);

        $response = $this->get(route('reports.deliveries.print', ['shift' => '2026-11-09']))->assertOk();

        $numbers = $response->viewData('groups')->flatMap(fn (array $group) => collect($group['orders'])->pluck('number'))->all();
        $this->assertSame([$inside->id], $numbers);
        $this->assertNotContains($outside->id, $numbers);
    }

    public function test_the_shift_prints_keep_requiring_the_report_permission_and_feature(): void
    {
        $this->actingAsRole('Entregador');

        $this->get(route('reports.orders.print', ['shift' => '2026-11-09']))->assertForbidden();
    }
}
