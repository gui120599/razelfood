<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\CancelSale;
use App\Actions\Sales\FinalizeSale;
use App\Exceptions\SaleException;
use App\Models\Order;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Critério global §16.2: com dois tenants, venda/pedido/forma de pagamento/
 * produto de um nunca são lidos nem alterados pelo outro.
 */
class SaleTenantIsolationTest extends TestCase
{
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    private PaymentOption $cashA;

    private PaymentOption $cashB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        $this->a = $this->makeSaleTenant('lazzopizza');
        $this->b = $this->makeSaleTenant('emporiodapizza');
        $this->cashA = $this->makePaymentOption($this->a, 'Dinheiro', isCash: true);
        $this->cashB = $this->makePaymentOption($this->b, 'Dinheiro', isCash: true);
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function sellAs(Tenant $tenant, PaymentOption $option, float $total = 40): array
    {
        $this->actAsTenant($tenant);
        $order = $this->makeSaleOrder($tenant, $total);
        $sale = app(FinalizeSale::class)([$order->id], [], [['payment_option_id' => $option->id, 'amount' => $total]]);

        return [$order, $sale];
    }

    public function test_each_tenant_has_its_own_sale_sequence_and_data(): void
    {
        [$orderA, $saleA] = $this->sellAs($this->a, $this->cashA);
        [$orderB, $saleB] = $this->sellAs($this->b, $this->cashB, 55);

        $this->assertSame([1, 1], [$saleA->number, $saleB->number]);
        $this->assertSame($this->a->id, $saleA->tenant_id);
        $this->assertSame($this->b->id, $saleB->tenant_id);

        $this->actAsTenant($this->a);
        $this->assertSame([$saleA->id], Sale::pluck('id')->all());

        $this->actAsTenant($this->b);
        $this->assertSame([$saleB->id], Sale::pluck('id')->all());
        $this->assertSame($saleB->id, $orderB->fresh()->sale_id);
    }

    public function test_tenant_a_cannot_charge_an_order_of_tenant_b(): void
    {
        $this->actAsTenant($this->b);
        $orderB = $this->makeSaleOrder($this->b);

        $this->actAsTenant($this->a);

        try {
            app(FinalizeSale::class)([$orderB->id], [], [['payment_option_id' => $this->cashA->id, 'amount' => 40]]);
            $this->fail('Pedido de outro tenant não pode ser cobrado.');
        } catch (SaleException $exception) {
            $this->assertStringContainsString('não foram encontrados', $exception->getMessage());
        }

        $this->assertNull(Order::withoutGlobalScopes()->find($orderB->id)->sale_id);
        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
    }

    public function test_tenant_a_cannot_use_a_payment_option_or_product_of_tenant_b(): void
    {
        $this->actAsTenant($this->a);
        $orderA = $this->makeSaleOrder($this->a);
        $productB = $this->makeProduct($this->b, 'Produto do B', 9);

        foreach ([
            [[$orderA->id], [], [['payment_option_id' => $this->cashB->id, 'amount' => 40]], 'Forma de pagamento inválida'],
            [[], [['product_id' => $productB->id, 'quantity' => 1]], [['payment_option_id' => $this->cashA->id, 'amount' => 9]], 'não foram encontrados'],
        ] as [$orders, $items, $payments, $message]) {
            try {
                app(FinalizeSale::class)($orders, $items, $payments);
                $this->fail("Deveria recusar: {$message}");
            } catch (SaleException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
    }

    public function test_tenant_b_cannot_cancel_a_sale_of_tenant_a(): void
    {
        [, $saleA] = $this->sellAs($this->a, $this->cashA);

        $this->actAsTenant($this->b);

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('Venda não encontrada');

        app(CancelSale::class)($saleA, 'tentativa cruzada');
    }

    public function test_cancelling_in_one_tenant_does_not_touch_the_other_tenants_sale(): void
    {
        [, $saleA] = $this->sellAs($this->a, $this->cashA);
        [$orderB, $saleB] = $this->sellAs($this->b, $this->cashB);

        $this->actAsTenant($this->a);
        app(CancelSale::class)($saleA, 'só a venda do A');

        $this->assertSame($saleB->id, Order::withoutGlobalScopes()->find($orderB->id)->sale_id);
        $this->assertSame('finalized', Sale::withoutGlobalScopes()->find($saleB->id)->status->value);
    }
}
