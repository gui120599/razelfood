<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\FinalizeSale;
use App\Actions\Sales\SyncPdvActivation;
use App\Enums\OrderStatus;
use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Jobs\CancelUnpaidSalesJob;
use App\Models\Feature;
use App\Models\Order;
use App\Models\PaymentOption;
use App\Models\Plan;
use App\Models\Sale;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use App\Support\Sales\PdvCutoff;
use App\Support\Sales\SaleCancellationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Data de corte do PDV (`tenants.pdv_enabled_at`), elegibilidade dos pedidos
 * (de "Aceito" em diante e criados depois da ativação) e o que acontece quando
 * o tenant perde a feature.
 */
class PdvActivationTest extends TestCase
{
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function cutoff(Tenant $tenant): ?string
    {
        return DB::table('tenants')->where('id', $tenant->id)->value('pdv_enabled_at');
    }

    private function pdvFeature(): Feature
    {
        return Feature::firstOrCreate(['key' => FeatureKey::PDV], ['name' => 'PDV / Venda', 'is_available' => true]);
    }

    /**
     * Tira o PDV do tenant direto no banco (sem observers nem job), como acontece
     * quando as features de um plano são editadas.
     */
    private function losePdvSilently(Tenant ...$tenants): void
    {
        foreach ($tenants as $tenant) {
            DB::table('tenants')->where('id', $tenant->id)->update(['plan_id' => $this->planWithAllFeatures()->id, 'pdv_enabled_at' => null]);
        }
    }

    private function cashFor(Tenant $tenant): PaymentOption
    {
        return $this->makePaymentOption($tenant, 'Dinheiro', isCash: true);
    }

    // ---- data de corte ----------------------------------------------------

    public function test_cutoff_is_recorded_when_the_tenant_is_created_with_pdv_and_not_otherwise(): void
    {
        $with = $this->makeSaleTenant('comcaixa');
        $without = $this->makeSaleTenant('semcaixa', withPdv: false);

        $this->assertNotNull($this->cutoff($with));
        $this->assertNull($this->cutoff($without));
    }

    public function test_changing_the_plan_sets_and_clears_the_cutoff(): void
    {
        $tenant = $this->makeSaleTenant('lazzopizza', withPdv: false);
        $this->assertNull($this->cutoff($tenant));

        $tenant->update(['plan_id' => $this->planWithAllFeaturesAndPdv()->id]);
        $this->assertNotNull($this->cutoff($tenant));

        $tenant->update(['plan_id' => $this->planWithAllFeatures()->id]);
        $this->assertNull($this->cutoff($tenant));
    }

    public function test_override_enables_and_disables_the_pdv_and_toggles_the_cutoff(): void
    {
        Queue::fake();
        $tenant = $this->makeSaleTenant('lazzopizza', withPdv: false);
        $override = $tenant->featureOverrides()->create(['feature_id' => $this->pdvFeature()->id, 'enabled' => true]);

        $this->assertNotNull($this->cutoff($tenant));
        Queue::assertNothingPushed();

        $override->update(['enabled' => false]);

        $this->assertNull($this->cutoff($tenant));
        Queue::assertPushed(CancelUnpaidSalesJob::class, fn (CancelUnpaidSalesJob $job): bool => $job->tenantId === $tenant->id);
    }

    public function test_sync_is_idempotent_and_keeps_the_original_cutoff(): void
    {
        $tenant = $this->makeSaleTenant('lazzopizza');
        $first = $this->cutoff($tenant);

        $this->travelTo(now()->addHour());
        app(SyncPdvActivation::class)($tenant);
        app(SyncPdvActivation::class)($tenant);

        $this->assertSame($first, $this->cutoff($tenant));
    }

    public function test_scheduled_command_catches_plan_feature_edits_that_no_observer_sees(): void
    {
        Queue::fake();
        $plan = $this->planWithAllFeatures();
        $tenant = Tenant::create([
            'name' => 'Lazzo', 'slug' => 'lazzopizza', 'status' => 'active',
            'whatsapp_number' => '5511999999999', 'plan_id' => $plan->id,
        ]);
        $this->assertNull($this->cutoff($tenant));

        $plan->features()->attach($this->pdvFeature());
        $this->assertNull($this->cutoff($tenant), 'Editar o plano não dispara observer de tenant.');

        $this->artisan('sales:sync-pdv-activation')->assertSuccessful();
        $this->assertNotNull($this->cutoff($tenant));

        $plan->features()->detach($this->pdvFeature()->id);
        $this->artisan('sales:sync-pdv-activation')->assertSuccessful();

        $this->assertNull($this->cutoff($tenant));
        Queue::assertPushed(CancelUnpaidSalesJob::class);
    }

    public function test_cutoff_is_written_lazily_when_the_feature_is_on_but_the_date_is_missing(): void
    {
        $tenant = $this->makeSaleTenant('lazzopizza');
        DB::table('tenants')->where('id', $tenant->id)->update(['pdv_enabled_at' => null]);
        $this->actAsTenant($tenant->fresh());

        $this->assertNotNull(PdvCutoff::for($tenant->fresh()));
        $this->assertNotNull($this->cutoff($tenant));
    }

    // ---- elegibilidade ----------------------------------------------------

    public function test_only_accepted_orders_created_after_the_activation_are_eligible(): void
    {
        $tenant = $this->makeSaleTenant('lazzopizza');
        $this->actAsTenant($tenant);

        $started = $this->makeSaleOrder($tenant, 10, OrderStatus::Started);
        $accepted = $this->makeSaleOrder($tenant, 20, OrderStatus::Open);
        $ready = $this->makeSaleOrder($tenant, 30, OrderStatus::Ready);
        $cancelled = $this->makeSaleOrder($tenant, 40, OrderStatus::Cancelled);
        $legacy = $this->makeSaleOrder($tenant, 50, OrderStatus::Finished);
        DB::table('orders')->where('id', $legacy->id)->update(['created_at' => now()->subDays(3)]);

        $this->assertEqualsCanonicalizing(
            [$accepted->id, $ready->id],
            Order::awaitingSale()->pluck('id')->all(),
        );
        $this->assertNotContains($started->id, Order::awaitingSale()->pluck('id')->all());
        $this->assertNotContains($cancelled->id, Order::awaitingSale()->pluck('id')->all());
    }

    public function test_finalize_sale_enforces_the_same_eligibility(): void
    {
        $tenant = $this->makeSaleTenant('lazzopizza');
        $this->actAsTenant($tenant);
        $cash = $this->cashFor($tenant);

        $started = $this->makeSaleOrder($tenant, 10, OrderStatus::Started);
        $legacy = $this->makeSaleOrder($tenant, 50, OrderStatus::Finished);
        DB::table('orders')->where('id', $legacy->id)->update(['created_at' => now()->subDays(3)]);

        foreach ([[$started, 'ainda não foi aceito'], [$legacy, 'anterior à ativação do PDV']] as [$order, $message]) {
            try {
                app(FinalizeSale::class)([$order->id], [], [['payment_option_id' => $cash->id, 'amount' => $order->grand_total]]);
                $this->fail("Deveria recusar: {$message}");
            } catch (SaleException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertSame(0, Sale::count());
    }

    public function test_tenant_without_pdv_cannot_finalize_even_a_standalone_sale(): void
    {
        $tenant = $this->makeSaleTenant('semcaixa', withPdv: false);
        $this->actAsTenant($tenant);
        $cash = $this->cashFor($tenant);
        $product = $this->makeProduct($tenant, 'Água', 5);

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('PDV não está ativo');

        app(FinalizeSale::class)([], [['product_id' => $product->id, 'quantity' => 1]], [['payment_option_id' => $cash->id, 'amount' => 5]]);
    }

    public function test_reactivation_starts_a_new_cutoff_so_old_orders_do_not_reappear(): void
    {
        $tenant = $this->makeSaleTenant('lazzopizza', withPdv: false);
        $override = $tenant->featureOverrides()->create(['feature_id' => $this->pdvFeature()->id, 'enabled' => true]);
        $this->actAsTenant($tenant);
        $before = $this->makeSaleOrder($tenant, 40);
        $this->assertSame([$before->id], Order::awaitingSale()->pluck('id')->all());

        $override->update(['enabled' => false]);
        $this->assertSame([], Order::awaitingSale()->pluck('id')->all(), 'Sem PDV, nenhum pedido é elegível.');

        $this->travelTo(now()->addHour());
        $override->update(['enabled' => true]);
        $after = $this->makeSaleOrder($tenant, 25);

        $this->assertSame([$after->id], Order::awaitingSale()->pluck('id')->all());
    }

    // ---- perda do PDV: cancela só o que não tem pagamento -----------------

    public function test_losing_the_pdv_cancels_unpaid_sales_but_keeps_paid_ones(): void
    {
        $tenant = $this->makeSaleTenant('lazzopizza');
        $this->actAsTenant($tenant);
        $cash = $this->cashFor($tenant);

        $freeOrder = $this->makeSaleOrder($tenant, 0);
        $unpaid = app(FinalizeSale::class)([$freeOrder->id], [], []);
        $paidOrder = $this->makeSaleOrder($tenant, 40);
        $paid = app(FinalizeSale::class)([$paidOrder->id], [], [['payment_option_id' => $cash->id, 'amount' => 40]]);

        $tenant->featureOverrides()->create(['feature_id' => $this->pdvFeature()->id, 'enabled' => false]);

        $this->assertSame(SaleStatus::Cancelled, $unpaid->fresh()->status);
        $this->assertStringContainsString('PDV foi desativado', $unpaid->fresh()->cancellation_note);
        $this->assertNull($freeOrder->fresh()->sale_id);
        $this->assertSame(SaleStatus::Finalized, $paid->fresh()->status, 'Venda com pagamento não é cancelada automaticamente.');
        $this->assertSame($paid->id, $paidOrder->fresh()->sale_id);
    }

    public function test_the_job_respects_the_fiscal_hook_and_only_touches_its_own_tenant(): void
    {
        $a = $this->makeSaleTenant('lazzopizza');
        $b = $this->makeSaleTenant('emporiodapizza');

        $this->actAsTenant($a);
        $unpaidA = app(FinalizeSale::class)([$this->makeSaleOrder($a, 0)->id], [], []);
        $this->actAsTenant($b);
        $unpaidB = app(FinalizeSale::class)([$this->makeSaleOrder($b, 0)->id], [], []);
        $this->forgetTenantContext();
        $this->losePdvSilently($a, $b);

        CancelUnpaidSalesJob::dispatchSync($a->id);

        $this->assertSame(SaleStatus::Cancelled, Sale::withoutGlobalScopes()->find($unpaidA->id)->status);
        $this->assertSame(SaleStatus::Finalized, Sale::withoutGlobalScopes()->find($unpaidB->id)->status, 'O job do tenant A não toca nas vendas do B.');
        $this->assertNull(CurrentTenant::get(), 'O contexto do tenant é liberado ao fim do job.');

        $this->app->bind(SaleCancellationGuard::class, fn () => new class extends SaleCancellationGuard
        {
            public function blockedReason(Sale $sale): ?string
            {
                return 'Documento fiscal vivo.';
            }
        });

        CancelUnpaidSalesJob::dispatchSync($b->id);

        $this->assertSame(SaleStatus::Finalized, Sale::withoutGlobalScopes()->find($unpaidB->id)->status, 'O gancho fiscal bloqueia o cancelamento automático.');
    }

    public function test_a_sale_of_a_plan_without_pdv_is_not_listed_as_pending_either(): void
    {
        $tenant = $this->makeSaleTenant('semcaixa', withPdv: false);
        $this->actAsTenant($tenant);
        $this->makeSaleOrder($tenant, 40);

        $this->assertSame(0, Order::awaitingSale()->count());
        $this->assertInstanceOf(Plan::class, $tenant->plan);
    }

    // ---- robustez do cancelamento automático -------------------------------

    public function test_a_stale_job_does_nothing_when_the_pdv_was_turned_back_on(): void
    {
        $tenant = $this->makeSaleTenant('lazzopizza');
        $this->actAsTenant($tenant);
        $free = app(FinalizeSale::class)([$this->makeSaleOrder($tenant, 0)->id], [], []);
        $this->forgetTenantContext();

        // O PDV continua ligado: um job despachado antes de uma reativação não pode cancelar nada.
        CancelUnpaidSalesJob::dispatchSync($tenant->id);

        $this->assertSame(SaleStatus::Finalized, Sale::withoutGlobalScopes()->find($free->id)->status);
    }

    public function test_the_job_blames_the_fiscal_hook_in_the_log_and_keeps_the_sale(): void
    {
        $tenant = $this->makeSaleTenant('lazzopizza');
        $this->actAsTenant($tenant);
        $free = app(FinalizeSale::class)([$this->makeSaleOrder($tenant, 0)->id], [], []);
        $this->forgetTenantContext();
        $this->losePdvSilently($tenant);

        $this->app->bind(SaleCancellationGuard::class, fn () => new class extends SaleCancellationGuard
        {
            public function blockedReason(Sale $sale): ?string
            {
                return 'Documento fiscal vivo.';
            }
        });
        Log::partialMock()
            ->shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Documento fiscal vivo.') && $context['tenant_id'] === $tenant->id);

        CancelUnpaidSalesJob::dispatchSync($tenant->id);

        $this->assertSame(SaleStatus::Finalized, Sale::withoutGlobalScopes()->find($free->id)->status);
    }

    public function test_one_failing_sale_does_not_stop_the_others_and_the_job_fails_at_the_end(): void
    {
        $tenant = $this->makeSaleTenant('lazzopizza');
        $this->actAsTenant($tenant);
        $first = app(FinalizeSale::class)([$this->makeSaleOrder($tenant, 0)->id], [], []);
        $second = app(FinalizeSale::class)([$this->makeSaleOrder($tenant, 0)->id], [], []);
        $this->forgetTenantContext();
        $this->losePdvSilently($tenant);

        // Falha inesperada (não de regra de negócio) só na primeira venda, ex.: deadlock.
        $failingId = $first->id;
        $this->app->bind(SaleCancellationGuard::class, fn () => new class($failingId) extends SaleCancellationGuard
        {
            public function __construct(private readonly int $failingId) {}

            public function blockedReason(Sale $sale): ?string
            {
                if ($sale->id === $this->failingId) {
                    throw new \RuntimeException('deadlock simulado');
                }

                return null;
            }
        });

        $thrown = null;

        try {
            CancelUnpaidSalesJob::dispatchSync($tenant->id);
        } catch (\RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, 'O job deveria relançar a falha ao final, para a fila tentar de novo.');
        $this->assertSame('deadlock simulado', $thrown->getMessage());
        $this->assertSame(SaleStatus::Finalized, Sale::withoutGlobalScopes()->find($first->id)->status, 'A venda que falhou foi revertida.');
        $this->assertSame(SaleStatus::Cancelled, Sale::withoutGlobalScopes()->find($second->id)->status, 'A segunda venda foi cancelada apesar da falha na primeira.');
    }

    public function test_the_scheduled_command_redispatches_when_unpaid_sales_remain_after_the_pdv_was_lost(): void
    {
        $tenant = $this->makeSaleTenant('lazzopizza');
        $this->actAsTenant($tenant);
        $free = app(FinalizeSale::class)([$this->makeSaleOrder($tenant, 0)->id], [], []);
        $this->forgetTenantContext();

        // Perdeu o PDV sem passar por observer nem pelo despacho (ex.: despacho anterior falhou).
        DB::table('tenants')->where('id', $tenant->id)->update(['plan_id' => $this->planWithAllFeatures()->id, 'pdv_enabled_at' => null]);

        $this->artisan('sales:sync-pdv-activation')->assertSuccessful();

        $this->assertSame(SaleStatus::Cancelled, Sale::withoutGlobalScopes()->find($free->id)->status);
    }

    public function test_losing_the_pdv_dispatches_the_job_only_once(): void
    {
        Queue::fake();
        $tenant = $this->makeSaleTenant('lazzopizza', withPdv: false);
        $tenant->featureOverrides()->create(['feature_id' => $this->pdvFeature()->id, 'enabled' => true]);
        $tenant->featureOverrides()->first()->update(['enabled' => false]);

        app(SyncPdvActivation::class)($tenant);
        $this->artisan('sales:sync-pdv-activation')->assertSuccessful();

        Queue::assertPushed(CancelUnpaidSalesJob::class, 1);
    }

    public function test_finalize_sale_uses_the_database_cutoff_as_the_source_of_truth(): void
    {
        $tenant = $this->makeSaleTenant('lazzopizza');
        $this->actAsTenant($tenant); // CurrentTenant guarda o model com plano que ainda tem pdv
        $product = $this->makeProduct($tenant, 'Água', 5);
        $cash = $this->cashFor($tenant);
        $this->assertTrue($tenant->hasFeature(FeatureKey::PDV)); // aquece as relações do model em cache
        $this->losePdvSilently($tenant); // no banco o tenant já perdeu o PDV; o model em CurrentTenant segue defasado

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('PDV não está ativo');

        app(FinalizeSale::class)([], [['product_id' => $product->id, 'quantity' => 1]], [['payment_option_id' => $cash->id, 'amount' => 5]]);
    }
}
