<?php

namespace Tests\Feature\Orders;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Pages\Kitchen;
use App\Models\BusinessHour;
use App\Models\Client;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\CurrentTenant;
use App\Support\Orders\ResolveActiveShiftStart;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Fase 1.S.2b: corte por turno nas colunas em andamento ("N pedidos de turnos anteriores"), coluna
 * Finalizados por turno escolhido com "carregar mais" e colunas visíveis por usuário.
 * Relógio fixo em 2026-11-09 12:00 (segunda-feira), sem horário cadastrado: o turno começa à 00:00.
 */
class KitchenShiftAndColumnsTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $manager;

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

        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->manager->assignRole('Gerente');
        $this->actingAs($this->manager);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeOrder(array $overrides = [], ?Tenant $tenant = null): Order
    {
        $tenant ??= $this->tenant;
        $client = Client::create(['tenant_id' => $tenant->id, 'name' => 'Cliente '.fake()->unique()->numerify('###'), 'phone' => fake()->unique()->numerify('119########')]);

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

    /** HTML da tela com os espaços e quebras de linha normalizados (os textos do Blade se espalham em várias linhas). */
    private function flatten($component): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags($component->html())));
    }

    private function idsOn(Kitchen $page, OrderStatus $status): array
    {
        return $page->ordersByStatus()->get($status->value, collect())->pluck('id')->sort()->values()->all();
    }

    // ─── Corte por turno ─────────────────────────────────────────────────────────────────────

    public function test_orders_from_previous_shifts_leave_the_columns_and_go_to_the_counter(): void
    {
        $current = $this->makeOrder(['opened_at' => now()->subHours(2)]);
        $stale = $this->makeOrder(['opened_at' => now()->subDay()]);

        $component = Livewire::test(Kitchen::class);
        $page = $component->instance();

        $this->assertSame([$current->id], $this->idsOn($page, OrderStatus::Open));
        $this->assertSame([OrderStatus::Open->value => 1], $page->outOfShiftCounts());
        $this->assertSame(1, $page->outOfShiftTotal());
        $this->assertNotNull($stale);
        $this->assertStringContainsString('1 pedido de turnos anteriores (mostrar)', $this->flatten($component));
    }

    public function test_showing_out_of_shift_orders_brings_them_back_with_a_warning(): void
    {
        $current = $this->makeOrder(['opened_at' => now()->subHours(2)]);
        $stale = $this->makeOrder(['opened_at' => now()->subDay()]);

        $component = Livewire::test(Kitchen::class)
            ->assertDontSee('Mostrando também pedidos abertos antes do turno atual')
            ->call('toggleOutOfShift')
            ->assertSet('showOutOfShift', true)
            ->assertSee('Mostrando também pedidos abertos antes do turno atual')
            ->assertSee('(ocultar)');

        $this->assertSame([$current->id, $stale->id], $this->idsOn($component->instance(), OrderStatus::Open));
    }

    public function test_counter_is_plural_and_hidden_when_there_is_nothing_out_of_shift(): void
    {
        Livewire::test(Kitchen::class)->assertDontSee('de turnos anteriores');

        $this->makeOrder(['opened_at' => now()->subDay()]);
        $this->makeOrder(['opened_at' => now()->subDays(2), 'status' => OrderStatus::Preparing]);

        $this->assertStringContainsString('2 pedidos de turnos anteriores', $this->flatten(Livewire::test(Kitchen::class)));
    }

    public function test_an_explicit_period_replaces_the_shift_window(): void
    {
        $stale = $this->makeOrder(['opened_at' => now()->subDays(3), 'created_at' => now()->subDays(3)]);

        $component = Livewire::test(Kitchen::class);
        $this->assertSame([], $this->idsOn($component->instance(), OrderStatus::Open));

        $component->set('periodFrom', now()->subDays(4)->toDateString());
        $this->assertSame([$stale->id], $this->idsOn($component->instance(), OrderStatus::Open));
    }

    public function test_the_shift_follows_the_business_hours_of_the_tenant(): void
    {
        // Segunda-feira 12:00, turno aberto às 07:00: o pedido das 06:30 é do turno anterior.
        BusinessHour::create(['tenant_id' => $this->tenant->id, 'weekday' => 1, 'opens_at' => '07:00:00', 'closes_at' => '23:00:00', 'is_active' => true]);

        $inside = $this->makeOrder(['opened_at' => Carbon::parse('2026-11-09 07:30')]);
        $this->makeOrder(['opened_at' => Carbon::parse('2026-11-09 06:30')]);

        $page = Livewire::test(Kitchen::class)->instance();

        $this->assertSame([$inside->id], $this->idsOn($page, OrderStatus::Open));
        $this->assertSame(1, $page->outOfShiftTotal());
    }

    public function test_the_out_of_shift_counter_never_counts_another_tenants_orders(): void
    {
        $other = Tenant::create(['name' => 'Outro', 'slug' => 'outro', 'status' => TenantStatus::Active, 'whatsapp_number' => '5511888888888']);
        $this->makeOrder(['opened_at' => now()->subDays(5)], $other);

        $page = Livewire::test(Kitchen::class)->instance();

        $this->assertSame(0, $page->outOfShiftTotal());
    }

    // ─── Coluna Finalizados ──────────────────────────────────────────────────────────────────

    public function test_finished_column_shows_the_chosen_shift_by_delivery_and_finish_dates(): void
    {
        $today = $this->makeOrder(['status' => OrderStatus::Finished, 'finished_at' => now()->subHour(), 'opened_at' => now()->subHours(3)]);
        $delivered = $this->makeOrder(['status' => OrderStatus::Delivered, 'delivered_at' => now()->subHours(2), 'opened_at' => now()->subHours(4)]);
        $yesterday = $this->makeOrder(['status' => OrderStatus::Finished, 'finished_at' => now()->subDay(), 'opened_at' => now()->subDay()->subHour()]);

        $component = Livewire::test(Kitchen::class)->assertSet('finishedDate', '2026-11-09');

        $page = $component->instance();
        $this->assertSame([$delivered->id], $this->idsOn($page, OrderStatus::Delivered));
        $this->assertSame([$today->id], $this->idsOn($page, OrderStatus::Finished));

        $component->set('finishedDate', '2026-11-08');
        $this->assertSame([$yesterday->id], $this->idsOn($component->instance(), OrderStatus::Finished));
        $this->assertSame([], $this->idsOn($component->instance(), OrderStatus::Delivered));
    }

    public function test_finished_orders_without_timestamps_fall_back_to_the_opening_date(): void
    {
        $legacy = $this->makeOrder(['status' => OrderStatus::Finished, 'opened_at' => now()->subHours(2)]);

        $page = Livewire::test(Kitchen::class)->instance();

        $this->assertSame([$legacy->id], $this->idsOn($page, OrderStatus::Finished));
    }

    public function test_finished_column_is_limited_and_loads_more_on_demand(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $this->makeOrder(['status' => OrderStatus::Finished, 'finished_at' => now()->subMinutes(10), 'opened_at' => now()->subHour()]);
        }

        $component = Livewire::test(Kitchen::class)->assertSee('Carregar mais finalizados');
        $this->assertCount(50, $component->instance()->ordersByStatus()->get(OrderStatus::Finished->value));

        $component->call('expandFinished')->assertSet('finishedExpanded', true)->assertDontSee('Carregar mais finalizados');
        $this->assertCount(51, $component->instance()->ordersByStatus()->get(OrderStatus::Finished->value));

        // Trocar o turno volta ao limite normal.
        $component->set('finishedDate', '2026-11-08')->assertSet('finishedExpanded', false);
    }

    public function test_load_more_is_not_offered_when_there_are_exactly_as_many_finished_orders_as_the_limit(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->makeOrder(['status' => OrderStatus::Finished, 'finished_at' => now()->subMinutes(10), 'opened_at' => now()->subHour()]);
        }

        $component = Livewire::test(Kitchen::class)->assertDontSee('Carregar mais finalizados');
        $this->assertCount(50, $component->instance()->ordersByStatus()->get(OrderStatus::Finished->value));
    }

    public function test_finished_column_has_the_shift_date_picker_and_the_history_link(): void
    {
        Livewire::test(Kitchen::class)
            ->assertSee('Turno de')
            ->assertSeeHtml('wire:model.live.debounce.700ms="finishedDate"')
            ->assertSee('Ver histórico completo');
    }

    public function test_the_default_shift_date_is_the_start_of_the_current_shift_even_after_midnight(): void
    {
        // Turno que abriu à noite de domingo e atravessa a meia-noite: às 01:30 de segunda ainda é o de domingo.
        BusinessHour::create(['tenant_id' => $this->tenant->id, 'weekday' => 0, 'opens_at' => '19:00:00', 'closes_at' => '02:00:00', 'is_active' => true]);
        Carbon::setTestNow('2026-11-09 01:30:00');

        Livewire::test(Kitchen::class)->assertSet('finishedDate', '2026-11-08');
    }

    // ─── Janela do dia de abertura ───────────────────────────────────────────────────────────

    public function test_window_for_an_opening_day_uses_the_earliest_opening_of_that_weekday(): void
    {
        BusinessHour::create(['tenant_id' => $this->tenant->id, 'weekday' => 1, 'opens_at' => '18:00:00', 'closes_at' => '23:00:00', 'is_active' => true]);
        BusinessHour::create(['tenant_id' => $this->tenant->id, 'weekday' => 1, 'opens_at' => '11:00:00', 'closes_at' => '15:00:00', 'is_active' => true]);
        BusinessHour::create(['tenant_id' => $this->tenant->id, 'weekday' => 1, 'opens_at' => '06:00:00', 'closes_at' => '07:00:00', 'is_active' => false]);

        [$start, $end] = app(ResolveActiveShiftStart::class)->windowForOpeningDay('2026-11-09');

        $this->assertSame('2026-11-09 11:00:00', $start->toDateTimeString());
        $this->assertSame('2026-11-10 11:00:00', $end->toDateTimeString());
    }

    public function test_consecutive_opening_days_tile_without_overlap_and_the_end_is_exclusive(): void
    {
        BusinessHour::create(['tenant_id' => $this->tenant->id, 'weekday' => 5, 'opens_at' => '18:00:00', 'closes_at' => '23:00:00', 'is_active' => true]);
        BusinessHour::create(['tenant_id' => $this->tenant->id, 'weekday' => 6, 'opens_at' => '11:00:00', 'closes_at' => '23:00:00', 'is_active' => true]);

        $resolver = app(ResolveActiveShiftStart::class);
        [$fridayStart, $fridayEnd] = $resolver->windowForOpeningDay('2026-11-06');
        [$saturdayStart] = $resolver->windowForOpeningDay('2026-11-07');

        $this->assertSame('2026-11-06 18:00:00', $fridayStart->toDateTimeString());
        $this->assertSame('2026-11-07 11:00:00', $fridayEnd->toDateTimeString());
        $this->assertTrue($fridayEnd->equalTo($saturdayStart));

        // Um pedido exatamente na fronteira pertence só à janela que COMEÇA nela.
        $boundary = $this->makeOrder(['status' => OrderStatus::Finished, 'finished_at' => Carbon::parse('2026-11-07 11:00:00'), 'opened_at' => Carbon::parse('2026-11-07 10:00:00')]);

        $component = Livewire::test(Kitchen::class)->set('finishedDate', '2026-11-06');
        $this->assertSame([], $this->idsOn($component->instance(), OrderStatus::Finished));

        $component->set('finishedDate', '2026-11-07');
        $this->assertSame([$boundary->id], $this->idsOn($component->instance(), OrderStatus::Finished));
    }

    public function test_an_order_with_both_delivery_and_finish_stamps_appears_in_one_window_only(): void
    {
        $order = $this->makeOrder([
            'status' => OrderStatus::Finished,
            'delivered_at' => Carbon::parse('2026-11-08 23:00:00'),
            'finished_at' => Carbon::parse('2026-11-09 01:00:00'),
            'opened_at' => Carbon::parse('2026-11-08 22:00:00'),
        ]);

        $component = Livewire::test(Kitchen::class)->set('finishedDate', '2026-11-08');
        $this->assertSame([], $this->idsOn($component->instance(), OrderStatus::Finished));

        $component->set('finishedDate', '2026-11-09');
        $this->assertSame([$order->id], $this->idsOn($component->instance(), OrderStatus::Finished));
    }

    public function test_window_for_an_opening_day_starts_at_midnight_without_business_hours(): void
    {
        [$start, $end] = app(ResolveActiveShiftStart::class)->windowForOpeningDay(Carbon::parse('2026-11-04'));

        $this->assertSame('2026-11-04 00:00:00', $start->toDateTimeString());
        $this->assertSame('2026-11-05 00:00:00', $end->toDateTimeString());
    }

    // ─── Colunas visíveis ────────────────────────────────────────────────────────────────────

    public function test_hiding_a_column_removes_it_from_the_board_and_is_remembered_per_user(): void
    {
        Livewire::test(Kitchen::class)
            ->assertSeeHtml('id="coluna-ready"')
            ->call('toggleColumn', OrderStatus::Ready->value)
            ->assertSet('hiddenColumns', ['ready'])
            ->assertDontSeeHtml('id="coluna-ready"')
            ->assertSeeHtml('--painel-colunas: 5')
            ->assertSee('(1 oculta)');

        $this->assertSame(['hidden' => ['ready']], UserPreference::valueFor($this->manager, 'kitchen.columns'));

        // Nova abertura da tela do mesmo usuário: continua escondida.
        Livewire::test(Kitchen::class)->assertSet('hiddenColumns', ['ready'])->assertDontSeeHtml('id="coluna-ready"');

        // Outro usuário do mesmo tenant não é afetado.
        $other = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $other->assignRole('Gerente');
        $this->actingAs($other);
        Livewire::test(Kitchen::class)->assertSet('hiddenColumns', [])->assertSeeHtml('id="coluna-ready"');
    }

    public function test_hiding_the_finished_column_and_showing_it_again(): void
    {
        Livewire::test(Kitchen::class)
            ->call('toggleColumn', OrderStatus::Delivered->value)
            ->assertDontSeeHtml('id="coluna-delivered"')
            ->call('toggleColumn', OrderStatus::Delivered->value)
            ->assertSet('hiddenColumns', [])
            ->assertSeeHtml('id="coluna-delivered"');
    }

    public function test_the_last_visible_column_cannot_be_hidden_and_invalid_statuses_are_ignored(): void
    {
        $component = Livewire::test(Kitchen::class);

        foreach (['started', 'open', 'preparing', 'ready', 'in_transit'] as $status) {
            $component->call('toggleColumn', $status);
        }

        $component->assertSet('hiddenColumns', ['started', 'open', 'preparing', 'ready', 'in_transit'])
            ->call('toggleColumn', OrderStatus::Delivered->value)
            ->assertSet('hiddenColumns', ['started', 'open', 'preparing', 'ready', 'in_transit'])
            ->assertNotified('Mantenha ao menos uma coluna visível.')
            ->call('toggleColumn', 'cancelled')
            ->call('toggleColumn', 'inexistente')
            ->assertSet('hiddenColumns', ['started', 'open', 'preparing', 'ready', 'in_transit']);
    }

    public function test_the_columns_menu_lists_every_available_column(): void
    {
        Livewire::test(Kitchen::class)
            ->assertSeeHtml("wire:click=\"toggleColumn('started')\"")
            ->assertSeeHtml("wire:click=\"toggleColumn('delivered')\"")
            ->assertSee('Colunas');
    }

    public function test_the_in_transit_column_is_not_offered_when_the_tenant_skips_that_stage(): void
    {
        $this->tenant->update(['uses_in_transit_stage' => false]);
        CurrentTenant::set($this->tenant->fresh());

        Livewire::test(Kitchen::class)->assertDontSeeHtml("wire:click=\"toggleColumn('in_transit')\"");
    }

    public function test_navigation_chips_use_the_short_status_names(): void
    {
        Livewire::test(Kitchen::class)
            ->assertSee('Iniciado')
            ->assertSee('Aberto')
            ->assertSee('Preparando')
            ->assertSee('Pronto')
            ->assertSee('Entregue');
    }

    public function test_the_finished_column_follows_the_current_shift_until_the_operator_picks_a_date(): void
    {
        $component = Livewire::test(Kitchen::class)->assertSet('finishedDate', '2026-11-09')->assertSet('finishedDateChosen', false);

        // Virou o dia com a tela aberta: o poll leva a coluna junto com o board.
        Carbon::setTestNow('2026-11-10 09:00:00');
        $component->call('refreshBoard')->assertSet('finishedDate', '2026-11-10');

        // Escolhida uma data, o poll não mexe mais.
        $component->set('finishedDate', '2026-11-05')->assertSet('finishedDateChosen', true);
        Carbon::setTestNow('2026-11-11 09:00:00');
        $component->call('refreshBoard')->assertSet('finishedDate', '2026-11-05');
    }

    public function test_an_invalid_shift_date_from_the_browser_falls_back_to_the_current_shift(): void
    {
        $order = $this->makeOrder(['status' => OrderStatus::Finished, 'finished_at' => now()->subHour(), 'opened_at' => now()->subHours(2)]);

        foreach (['next monday', '2026-02-30', '+99999999 years', 'abc', '1900-01-01'] as $forged) {
            $component = Livewire::test(Kitchen::class)->set('finishedDate', $forged);

            $component->assertSet('finishedDateChosen', false);
            $this->assertSame([$order->id], $this->idsOn($component->instance(), OrderStatus::Finished), "data forjada: {$forged}");
        }
    }

    public function test_the_out_of_shift_counter_is_not_shown_when_a_period_replaces_the_shift_window(): void
    {
        $this->makeOrder(['opened_at' => now()->subDays(3), 'created_at' => now()->subDays(3)]);

        $component = Livewire::test(Kitchen::class);
        $this->assertSame(1, $component->instance()->outOfShiftTotal());

        $component->set('periodFrom', now()->subDays(5)->toDateString());
        $this->assertSame(0, $component->instance()->outOfShiftTotal());
    }

    public function test_the_column_chosen_by_the_quick_filter_is_never_hidden(): void
    {
        $ready = $this->makeOrder(['status' => OrderStatus::Ready]);

        $component = Livewire::test(Kitchen::class)
            ->call('toggleColumn', OrderStatus::Ready->value)
            ->assertDontSeeHtml('id="coluna-ready"')
            ->set('quickFilter', 'ready')
            ->assertSeeHtml('id="coluna-ready"');

        $this->assertSame([$ready->id], $this->idsOn($component->instance(), OrderStatus::Ready));

        $component->set('quickFilter', 'finished')->assertSeeHtml('id="coluna-delivered"');
    }

    public function test_everything_comes_back_if_the_only_visible_column_disappears(): void
    {
        $component = Livewire::test(Kitchen::class);

        foreach (['started', 'open', 'preparing', 'ready', 'delivered'] as $status) {
            $component->call('toggleColumn', $status);
        }

        $component->assertSeeHtml('id="coluna-in-transit"');

        // O tenant desliga o estágio: a única coluna visível deixa de existir.
        $this->tenant->update(['uses_in_transit_stage' => false]);
        CurrentTenant::set($this->tenant->fresh());

        $component->call('$refresh')
            ->assertSeeHtml('id="coluna-started"')
            ->assertSeeHtml('id="coluna-delivered"')
            ->assertSeeHtml('--painel-colunas: 5');
    }

    public function test_forged_or_corrupt_hidden_columns_are_sanitized(): void
    {
        UserPreference::rememberFor($this->manager, 'kitchen.columns', ['hidden' => 'não é lista']);
        Livewire::test(Kitchen::class)->assertSet('hiddenColumns', []);

        UserPreference::rememberFor($this->manager, 'kitchen.columns', ['hidden' => ['ready', 'nada', ['x'], 'ready', 5]]);
        Livewire::test(Kitchen::class)->assertSet('hiddenColumns', ['ready']);

        // Valor adulterado pelo navegador não quebra o toggle nem é gravado.
        Livewire::test(Kitchen::class)
            ->set('hiddenColumns', [['x'], 'foo', 'started', 'started'])
            ->call('toggleColumn', 'open')
            ->assertSet('hiddenColumns', ['started', 'open']);

        $this->assertSame(['hidden' => ['started', 'open']], UserPreference::valueFor($this->manager, 'kitchen.columns'));
    }

    public function test_hidden_columns_are_not_queried(): void
    {
        $started = $this->makeOrder(['status' => OrderStatus::Started]);
        $finished = $this->makeOrder(['status' => OrderStatus::Finished, 'finished_at' => now(), 'opened_at' => now()->subHour()]);

        $component = Livewire::test(Kitchen::class)
            ->call('toggleColumn', OrderStatus::Started->value)
            ->call('toggleColumn', OrderStatus::Delivered->value);

        $page = $component->instance();
        $this->assertSame([], $this->idsOn($page, OrderStatus::Started));
        $this->assertSame([], $this->idsOn($page, OrderStatus::Finished));
        $this->assertNotNull($started);
        $this->assertNotNull($finished);
    }
}
