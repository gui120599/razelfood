<?php

namespace Tests\Feature;

use App\Livewire\Checkout;
use App\Models\BusinessHour;
use App\Models\Category;
use App\Models\Client;
use App\Models\DeliveryOption;
use App\Models\Order;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Cart;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Config `tenants.require_client_cpf` (Configurações de Pedidos): quando
 * ligada, o checkout online público mostra e exige um CPF válido.
 */
class CheckoutCpfTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private DeliveryOption $pickup;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->pickup = DeliveryOption::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Retirada no local',
            'delivery_fee' => 0,
            'requires_address' => false,
        ]);

        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);

        PaymentOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Cartão', 'is_cash' => false]);

        Cart::addSimple($product->id);
    }

    private function checkout(): Testable
    {
        return Livewire::test(Checkout::class)
            ->set('deliveryOptionId', $this->pickup->id)
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('payments.0.payment_option_id', PaymentOption::first()->id);
    }

    public function test_cpf_is_not_required_when_the_setting_is_off(): void
    {
        $this->checkout()
            ->assertSet('requiresCpf', false)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(1, Order::count());
        $this->assertNull(Client::first()->cpf);
    }

    public function test_cpf_is_required_when_the_setting_is_on(): void
    {
        $this->tenant->update(['require_client_cpf' => true]);

        $this->checkout()
            ->assertSet('requiresCpf', true)
            ->call('submit')
            ->assertHasErrors(['cpf'])
            ->assertDispatched('checkout-validation-failed', field: 'cpf');

        $this->assertSame(0, Order::count());
    }

    public function test_invalid_cpf_is_rejected(): void
    {
        $this->tenant->update(['require_client_cpf' => true]);

        $this->checkout()
            ->set('cpf', '111.111.111-11')
            ->call('submit')
            ->assertHasErrors(['cpf']);

        $this->assertSame(0, Order::count());
    }

    public function test_valid_masked_cpf_is_stored_as_digits_only(): void
    {
        $this->tenant->update(['require_client_cpf' => true]);

        $this->checkout()
            ->set('cpf', '529.982.247-25')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(1, Order::count());
        $this->assertSame('52998224725', Client::first()->cpf);
    }

    public function test_existing_client_cpf_is_prefilled_masked_on_phone_lookup(): void
    {
        Client::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cliente Antigo',
            'phone' => '11988887777',
            'cpf' => '52998224725',
        ]);

        Livewire::test(Checkout::class)
            ->set('phone', '11988887777')
            ->assertSet('cpf', '529.982.247-25');
    }
}
