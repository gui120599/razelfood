<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\FinalizeSale;
use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\SaleStatus;
use App\Filament\Tenant\Pages\Sales\CloseAccount;
use App\Filament\Tenant\Pages\Sales\PendingAccounts;
use App\Filament\Tenant\Resources\Orders\Pages\ListOrders;
use App\Filament\Tenant\Resources\Sales\Pages\ListSales;
use App\Filament\Tenant\Resources\Sales\Pages\ViewSale;
use App\Filament\Tenant\Resources\Sales\SaleResource;
use App\Models\OrderPayment;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Telas da Fase 1.0 no painel do tenant: "Contas a fechar", "Fechar conta" e o
 * Resource de vendas. Papéis e permissões reais (SeedDefaultTenantRoles).
 */
class SalesPagesTest extends TestCase
{
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private PaymentOption $cash;

    private PaymentOption $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        $this->tenant = $this->makeSaleTenant('lazzopizza');
        $this->actAsTenant($this->tenant);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(SeedDefaultTenantRoles::class)($this->tenant);

        $this->cash = $this->makePaymentOption($this->tenant, 'Dinheiro', isCash: true);
        $this->card = $this->makePaymentOption($this->tenant, 'Cartão');
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function sell(float $total = 40): Sale
    {
        $order = $this->makeSaleOrder($this->tenant, $total);

        return app(FinalizeSale::class)([$order->id], [], [['payment_option_id' => $this->cash->id, 'amount' => $total]]);
    }

    // ---- Contas a fechar -------------------------------------------------

    public function test_pending_accounts_lists_only_orders_without_sale_and_not_cancelled(): void
    {
        $this->actingAsRole('Caixa');
        $pending = $this->makeSaleOrder($this->tenant, 40, OrderStatus::Delivered);
        $preparing = $this->makeSaleOrder($this->tenant, 25, OrderStatus::Preparing);
        $cancelled = $this->makeSaleOrder($this->tenant, 30, OrderStatus::Cancelled);
        $sold = $this->makeSaleOrder($this->tenant, 20);
        app(FinalizeSale::class)([$sold->id], [], [['payment_option_id' => $this->cash->id, 'amount' => 20]]);

        Livewire::test(PendingAccounts::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$pending, $preparing])
            ->assertCanNotSeeTableRecords([$cancelled, $sold]);
    }

    public function test_pending_accounts_never_shows_orders_of_another_tenant(): void
    {
        $this->actingAsRole('Caixa');
        $own = $this->makeSaleOrder($this->tenant, 40);

        $other = $this->makeSaleTenant('emporiodapizza');
        $foreign = $this->makeSaleOrder($other, 99);

        Livewire::test(PendingAccounts::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_pending_accounts_row_and_bulk_actions_lead_to_the_close_screen(): void
    {
        $this->actingAsRole('Caixa');
        $a = $this->makeSaleOrder($this->tenant, 40);
        $b = $this->makeSaleOrder($this->tenant, 30);

        Livewire::test(PendingAccounts::class)
            ->assertTableActionHasUrl('closeAccount', CloseAccount::getUrl(['orders' => $a->id]), $a)
            ->callTableBulkAction('closeSelected', [$a, $b])
            ->assertRedirect(CloseAccount::getUrl(['orders' => "{$a->id},{$b->id}"]));
    }

    // ---- Fechar conta ----------------------------------------------------

    public function test_close_account_prefills_declared_payment_and_finalizes_the_sale(): void
    {
        $user = $this->actingAsRole('Caixa');
        $order = $this->makeSaleOrder($this->tenant, 40);
        OrderPayment::create(['order_id' => $order->id, 'payment_option_name' => 'Dinheiro', 'is_cash' => true, 'amount' => 40]);

        Livewire::withQueryParams(['orders' => (string) $order->id])
            ->test(CloseAccount::class)
            ->assertOk()
            ->assertSee('Total da conta')
            ->assertSee('R$ 40,00')
            ->assertSee('Conta coberta')
            ->call('finalize')
            ->assertHasNoFormErrors()
            ->assertNotified('Venda #1 finalizada');

        $sale = Sale::first();
        $this->assertNotNull($sale);
        $this->assertSame('40.00', $sale->total);
        $this->assertSame($user->id, $sale->finalized_by_user_id);
        $this->assertSame($sale->id, $order->fresh()->sale_id);
        $this->assertSame('Dinheiro', $sale->payments->first()->payment_option_name);
    }

    public function test_close_account_preview_shows_remaining_and_change(): void
    {
        $this->actingAsRole('Caixa');
        $order = $this->makeSaleOrder($this->tenant, 40);

        $component = Livewire::withQueryParams(['orders' => (string) $order->id])->test(CloseAccount::class);

        $component
            ->fillForm(['payments' => [['payment_option_id' => $this->cash->id, 'amount' => '15.00']]])
            ->assertSee('Falta receber')
            ->assertSee('R$ 25,00');

        $component
            ->fillForm(['payments' => [['payment_option_id' => $this->cash->id, 'amount' => '50.00']]])
            ->assertSee('R$ 10,00')
            ->assertSee('Conta coberta');

        $component
            ->fillForm(['payments' => [['payment_option_id' => $this->card->id, 'amount' => '50.00']]])
            ->assertSee('o troco só pode sair de pagamento em dinheiro');
    }

    public function test_close_account_rejects_insufficient_payment_with_a_toast_and_creates_nothing(): void
    {
        $this->actingAsRole('Caixa');
        $order = $this->makeSaleOrder($this->tenant, 40);

        Livewire::withQueryParams(['orders' => (string) $order->id])
            ->test(CloseAccount::class)
            ->fillForm(['payments' => [['payment_option_id' => $this->cash->id, 'amount' => '10.00']]])
            ->call('finalize')
            ->assertNotified()
            ->assertNoRedirect();

        $this->assertSame(0, Sale::count());
        $this->assertNull($order->fresh()->sale_id);
    }

    public function test_close_account_sells_standalone_items_without_any_order(): void
    {
        $this->actingAsRole('Caixa');
        $product = $this->makeProduct($this->tenant, 'Refrigerante', 8, ['controls_stock' => true, 'stock_quantity' => 5]);

        Livewire::test(CloseAccount::class)
            ->fillForm([
                'standalone_items' => [['product_id' => $product->id, 'quantity' => 2]],
                'payments' => [['payment_option_id' => $this->card->id, 'amount' => '16,00', 'card_brand' => 'Visa']],
            ])
            ->assertSee('R$ 16,00')
            ->call('finalize')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $sale = Sale::first();
        $this->assertSame('16.00', $sale->total);
        $this->assertSame('Visa', $sale->payments->first()->card_brand);
        $this->assertSame('3.00', $product->fresh()->stock_quantity);
    }

    public function test_close_account_cannot_be_submitted_twice_for_a_standalone_sale(): void
    {
        $this->actingAsRole('Caixa');
        $product = $this->makeProduct($this->tenant, 'Refrigerante', 8, ['controls_stock' => true, 'stock_quantity' => 10]);

        Livewire::test(CloseAccount::class)
            ->fillForm([
                'standalone_items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payments' => [['payment_option_id' => $this->cash->id, 'amount' => '8.00']],
            ])
            ->call('finalize')
            ->call('finalize'); // duplo clique

        $this->assertSame(1, Sale::count());
        $this->assertSame('9.00', $product->fresh()->stock_quantity, 'Estoque debitado uma única vez.');
    }

    public function test_close_account_order_ids_cannot_be_tampered_with_from_the_client(): void
    {
        $this->actingAsRole('Caixa');
        $own = $this->makeSaleOrder($this->tenant, 40);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::withQueryParams(['orders' => (string) $own->id])
            ->test(CloseAccount::class)
            ->set('orderIds', [999999]);
    }

    public function test_cancelled_orders_show_no_payment_status_in_the_history(): void
    {
        $this->actingAsRole('Admin');
        $cancelled = $this->makeSaleOrder($this->tenant, 40, OrderStatus::Cancelled);

        Livewire::test(ListOrders::class)
            ->assertTableColumnStateSet('payment_status', null, $cancelled);
    }

    public function test_close_account_ignores_orders_that_are_no_longer_available(): void
    {
        $this->actingAsRole('Caixa');
        $sold = $this->makeSaleOrder($this->tenant, 40);
        app(FinalizeSale::class)([$sold->id], [], [['payment_option_id' => $this->cash->id, 'amount' => 40]]);

        $component = Livewire::withQueryParams(['orders' => (string) $sold->id])->test(CloseAccount::class);

        $component->assertNotified()->assertSet('orderIds', []);
    }

    // ---- Vendas ----------------------------------------------------------

    public function test_sales_list_and_view_show_the_sale_details(): void
    {
        $this->actingAsRole('Caixa');
        $sale = $this->sell(40);

        Livewire::test(ListSales::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$sale]);

        Livewire::test(ViewSale::class, ['record' => $sale->id])
            ->assertOk()
            ->assertSee('Pagamentos recebidos')
            ->assertSee('Dinheiro')
            ->assertSee('Finalizada');
    }

    public function test_sales_of_another_tenant_are_not_listed(): void
    {
        $this->actingAsRole('Caixa');
        $own = $this->sell(40);

        $other = $this->makeSaleTenant('emporiodapizza');
        $this->actAsTenant($other);
        $otherCash = $this->makePaymentOption($other, 'Dinheiro', isCash: true);
        $foreignOrder = $this->makeSaleOrder($other, 77);
        $foreign = app(FinalizeSale::class)([$foreignOrder->id], [], [['payment_option_id' => $otherCash->id, 'amount' => 77]]);
        $this->actAsTenant($this->tenant);

        Livewire::test(ListSales::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_cancel_action_cancels_the_sale_with_a_note(): void
    {
        $user = $this->actingAsRole('Gerente');
        $order = $this->makeSaleOrder($this->tenant, 40);
        $sale = app(FinalizeSale::class)([$order->id], [], [['payment_option_id' => $this->cash->id, 'amount' => 40]]);

        Livewire::test(ViewSale::class, ['record' => $sale->id])
            ->assertActionVisible(TestAction::make('cancelSale'))
            ->callAction('cancelSale', ['note' => 'Cobrança duplicada'])
            ->assertNotified('Venda cancelada');

        $sale->refresh();
        $this->assertSame(SaleStatus::Cancelled, $sale->status);
        $this->assertSame($user->id, $sale->cancelled_by_user_id);
        $this->assertNull($order->fresh()->sale_id);
    }

    public function test_cancel_action_is_hidden_once_the_sale_is_cancelled(): void
    {
        $this->actingAsRole('Gerente');
        $sale = $this->sell(40);
        $sale->update(['status' => SaleStatus::Cancelled]);

        Livewire::test(ViewSale::class, ['record' => $sale->id])
            ->assertActionHidden(TestAction::make('cancelSale'));
    }

    public function test_cancel_is_blocked_without_manage_sales(): void
    {
        $sale = $this->sell(40);
        $user = $this->actingAsRole('Atendente');
        $user->givePermissionTo(SaleViewPermission::names());

        $component = Livewire::test(ViewSale::class, ['record' => $sale->id])
            ->assertActionHidden(TestAction::make('cancelSale'));

        try {
            // O Filament recusa chamar uma ação oculta/não autorizada (asserção de visibilidade).
            $component->callAction('cancelSale', ['note' => 'tentativa sem permissão']);
        } catch (AssertionFailedError) {
            // esperado
        }

        $this->assertSame(SaleStatus::Finalized, $sale->fresh()->status, 'A ação não pode rodar sem manage_sales.');
    }

    // ---- Papéis e feature ------------------------------------------------

    public function test_roles_caixa_and_gerente_can_close_accounts_but_atendente_cannot(): void
    {
        foreach (['Caixa' => true, 'Gerente' => true, 'Atendente' => false, 'Entregador' => false] as $role => $allowed) {
            $this->actingAsRole($role);

            $this->assertSame($allowed, PendingAccounts::canAccess(), "PendingAccounts para {$role}");
            $this->assertSame($allowed, CloseAccount::canAccess(), "CloseAccount para {$role}");
            $this->assertSame($allowed, SaleResource::canAccess(), "SaleResource para {$role}");
        }
    }

    public function test_atendente_gets_403_on_the_new_pages(): void
    {
        $this->actingAsRole('Atendente');

        Livewire::test(PendingAccounts::class)->assertForbidden();
        Livewire::test(CloseAccount::class)->assertForbidden();
        Livewire::test(ListSales::class)->assertForbidden();
    }

    public function test_admin_has_access_to_everything(): void
    {
        $this->actingAsRole('Admin');

        $this->assertTrue(PendingAccounts::canAccess());
        $this->assertTrue(CloseAccount::canAccess());
        $this->assertTrue(SaleResource::canAccess());
        $this->assertTrue(PendingAccounts::shouldRegisterNavigation());
        $this->assertFalse(CloseAccount::shouldRegisterNavigation(), 'A tela de fechamento não aparece no menu.');
    }

    public function test_tenant_without_pdv_gets_nothing_new_even_as_admin(): void
    {
        $plain = $this->makeSaleTenant('semcaixa', withPdv: false);
        $this->actAsTenant($plain);
        app(SeedDefaultTenantRoles::class)($plain);
        $admin = User::factory()->create(['tenant_id' => $plain->id]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $this->assertFalse(PendingAccounts::canAccess());
        $this->assertFalse(PendingAccounts::shouldRegisterNavigation());
        $this->assertFalse(CloseAccount::canAccess());
        $this->assertFalse(SaleResource::canAccess());
        $this->assertFalse(SaleResource::shouldRegisterNavigation());

        Livewire::test(PendingAccounts::class)->assertForbidden();
    }

    // ---- Histórico de pedidos --------------------------------------------

    public function test_order_history_shows_the_payment_column_only_with_pdv(): void
    {
        $this->actingAsRole('Admin');
        $order = $this->makeSaleOrder($this->tenant, 40);

        Livewire::test(ListOrders::class)
            ->assertTableColumnVisible('payment_status')
            ->assertTableColumnStateSet('payment_status', OrderPaymentStatus::Pending, $order);

        $plain = $this->makeSaleTenant('semcaixa', withPdv: false);
        $this->actAsTenant($plain);
        app(SeedDefaultTenantRoles::class)($plain);
        $admin = User::factory()->create(['tenant_id' => $plain->id]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(ListOrders::class)->assertTableColumnHidden('payment_status');
    }
}

/**
 * Nomes das permissões de leitura de venda geradas pelo Shield (resolvidas em
 * runtime, nunca hardcoded — dependem de config/filament-shield.php).
 */
final class SaleViewPermission
{
    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        $permissions = FilamentShield::getResources()[SaleResource::class]['permissions'] ?? [];

        return collect($permissions)->only(['viewAny', 'view'])->pluck('key')->filter()->values()->all();
    }
}
