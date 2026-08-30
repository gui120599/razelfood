<?php

namespace Tests\Feature;

use App\Livewire\Checkout;
use App\Models\BusinessHour;
use App\Models\Category;
use App\Models\DeliveryOption;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Cart;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Item 6 dos ajustes de checkout: falha de validação de campo obrigatório
 * dispara `checkout-validation-failed` com o nome do primeiro campo
 * inválido — o @script do checkout.blade.php usa isso pra rolar a página
 * até o campo (via `[data-field="..."]`) e focá-lo, em vez de só mostrar
 * um banner genérico no topo.
 */
class CheckoutValidationFocusTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Carrinho fica em sessão (App\Support\Cart) — sem isso, item de um
        // teste anterior desta classe (produto já removido pelo rollback do
        // RefreshDatabase) vaza pro carrinho do próximo teste.
        Cart::clear();

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);

        foreach (range(0, 6) as $weekday) {
            BusinessHour::create([
                'tenant_id' => $this->tenant->id,
                'weekday' => $weekday,
                'opens_at' => '00:00:00',
                'closes_at' => '23:59:59',
                'is_active' => true,
            ]);
        }

        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        PaymentOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Cartão', 'is_cash' => false]);

        Cart::addSimple($product->id);
    }

    public function test_missing_phone_and_name_focuses_phone_first(): void
    {
        Livewire::test(Checkout::class)
            ->call('submit')
            ->assertDispatched('checkout-validation-failed', field: 'phone');
    }

    public function test_missing_name_only_focuses_name(): void
    {
        Livewire::test(Checkout::class)
            ->set('phone', '11999990000')
            ->call('submit')
            ->assertDispatched('checkout-validation-failed', field: 'name');
    }

    public function test_missing_address_for_delivery_focuses_street(): void
    {
        $deliveryOption = DeliveryOption::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Entrega padrão',
            'delivery_fee' => 8,
            'requires_address' => true,
        ]);

        Livewire::test(Checkout::class)
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('deliveryOptionId', $deliveryOption->id)
            ->call('submit')
            ->assertDispatched('checkout-validation-failed', field: 'street');
    }

    public function test_a_business_rule_error_like_payment_mismatch_does_not_dispatch_the_focus_event(): void
    {
        Livewire::test(Checkout::class)
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('payments.0.amount', '10,00')
            ->call('submit')
            ->assertNotDispatched('checkout-validation-failed')
            ->assertSet('errorSection', 'payment');
    }

    public function test_empty_cart_error_is_classified_under_the_items_section(): void
    {
        Cart::clear();

        Livewire::test(Checkout::class)
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('payments.0.payment_option_id', PaymentOption::first()->id)
            ->call('submit')
            ->assertSet('errorSection', 'items');
    }

    public function test_business_hours_closed_error_falls_back_to_no_section(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Fechado',
            'slug' => 'tenant-fechado',
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);

        // Nenhum BusinessHour ativo cadastrado = fechado o tempo todo.
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $paymentOption = PaymentOption::create(['tenant_id' => $tenant->id, 'name' => 'Cartão', 'is_cash' => false]);

        // O carrinho é em sessão, sem isolamento por tenant — o item do
        // tenant do setUp() precisa sair antes de simular este segundo
        // tenant, senão cartLines() tenta resolver um produto que não
        // pertence (mais) ao tenant atual.
        Cart::clear();
        Cart::addSimple($product->id);

        $test = Livewire::test(Checkout::class)
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('payments.0.payment_option_id', $paymentOption->id)
            ->call('submit')
            ->assertSet('errorSection', null);

        $this->assertNotNull($test->get('errorMessage'));
    }
}
