<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\AttachOrderItems;
use App\Actions\Sales\FinalizeSale;
use App\Actions\Sales\StartSale;
use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Filament\Tenant\Pages\Sales\NewSale;
use App\Models\Order;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Forma de pagamento que exige bandeira/autorização (cadastro fiscal, Fase
 * 1.1): o fechamento da conta não finaliza sem esses dados.
 */
class PaymentRequirementsTest extends TestCase
{
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private PaymentOption $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        $this->tenant = $this->makeSaleTenant('lazzopizza');
        $this->actAsTenant($this->tenant);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(SeedDefaultTenantRoles::class)($this->tenant);

        $this->card = PaymentOption::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cartão', 'requires_card_brand' => true, 'requires_authorization_code' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function finalize(array $payment): Sale
    {
        $order = $this->makeSaleOrder($this->tenant, 40);

        return app(FinalizeSale::class)([$order->id], [], [['payment_option_id' => $this->card->id, 'amount' => 40] + $payment]);
    }

    public function test_finalizing_without_the_required_brand_or_authorization_is_refused(): void
    {
        foreach ([
            [[], 'bandeira'],
            [['card_brand' => 'Visa'], 'autorização'],
            [['card_brand' => '  ', 'authorization_code' => '123456'], 'bandeira'],
        ] as [$extra, $fragment]) {
            try {
                $this->finalize($extra);
                $this->fail("Deveria exigir: {$fragment}");
            } catch (SaleException $exception) {
                $this->assertStringContainsString($fragment, $exception->getMessage());
            }
        }

        $this->assertSame(0, Sale::count());
    }

    public function test_finalizing_with_both_fields_works_and_stores_them(): void
    {
        $sale = $this->finalize(['card_brand' => 'Visa', 'authorization_code' => '123456']);

        $this->assertSame('Visa', $sale->payments->first()->card_brand);
        $this->assertSame('123456', $sale->payments->first()->authorization_code);
    }

    public function test_an_option_that_does_not_require_them_still_finalizes_without(): void
    {
        $this->card->update(['requires_card_brand' => false, 'requires_authorization_code' => false]);

        $this->assertSame('40.00', $this->finalize([])->total);
    }

    private function draftFor(Order $order): Sale
    {
        return app(AttachOrderItems::class)(app(StartSale::class)(), $order->id);
    }

    public function test_the_new_sale_screen_requires_brand_and_authorization_when_the_option_demands(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole('Caixa');
        $this->actingAs($user);
        $order = $this->makeSaleOrder($this->tenant, 40);

        $component = Livewire::test(NewSale::class, ['sale' => $this->draftFor($order)->id])
            ->set('paymentOptionId', $this->card->id)
            ->set('receivedAmount', 40.0)
            ->call('registerPayment')
            ->assertHasErrors('receivedAmount');

        $this->assertSame(0, SalePayment::count(), 'Pagamento sem bandeira/autorização não é salvo.');
        $this->assertSame(0, Sale::where('status', SaleStatus::Finalized)->count());

        $component
            ->set('cardBrandText', 'Visa')
            ->set('authorizationCode', '999')
            ->call('registerPayment')
            ->assertHasNoErrors()
            ->call('finalizeSale')
            ->assertHasNoErrors();

        $this->assertSame(1, Sale::where('status', SaleStatus::Finalized)->count());
    }
}
