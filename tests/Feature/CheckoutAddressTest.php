<?php

namespace Tests\Feature;

use App\Livewire\Checkout;
use App\Models\BusinessHour;
use App\Models\Category;
use App\Models\Client;
use App\Models\DeliveryOption;
use App\Models\DeliveryZone;
use App\Models\Order;
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
 * RF-35 a RF-39: seção de endereço só aparece pra entrega, número pode ficar
 * "S/N" (mas aí complemento vira obrigatório), e a taxa é resolvida pelo
 * bairro no submit.
 */
class CheckoutAddressTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private DeliveryOption $deliveryOption;

    private Product $product;

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

        // Aberto 24h todo dia, pra não depender do horário em que os testes rodam.
        foreach (range(0, 6) as $weekday) {
            BusinessHour::create([
                'tenant_id' => $this->tenant->id,
                'weekday' => $weekday,
                'opens_at' => '00:00:00',
                'closes_at' => '23:59:59',
                'is_active' => true,
            ]);
        }

        $this->deliveryOption = DeliveryOption::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Entrega padrão',
            'delivery_fee' => 8,
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pizzas',
            'display_order' => 1,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Calabresa',
            'price' => 40,
        ]);

        // Não-dinheiro de propósito: testes deste arquivo cobrem endereço/entrega,
        // não o fluxo de troco (ver CheckoutChangeForTest) — PaymentOption::first()
        // não deve exigir o campo changeFor no submit.
        PaymentOption::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cartão',
            'is_cash' => false,
        ]);

        Cart::addSimple($this->product->id);
    }

    public function test_address_section_is_hidden_until_a_delivery_option_is_chosen(): void
    {
        Livewire::test(Checkout::class)
            ->assertDontSee('Endereço de entrega')
            ->set('deliveryOptionId', $this->deliveryOption->id)
            ->assertSee('Endereço de entrega');
    }

    public function test_submit_requires_complement_when_number_is_blank(): void
    {
        Livewire::test(Checkout::class)
            ->set('deliveryOptionId', $this->deliveryOption->id)
            ->call('revealManualAddress')
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('street', 'Rua das Flores')
            ->set('neighborhood', 'Centro')
            ->set('number', '')
            ->set('complement', '')
            ->set('payments.0.payment_option_id', PaymentOption::first()->id)
            ->call('submit')
            ->assertHasErrors(['complement' => 'required']);

        $this->assertSame(0, Order::count());
    }

    public function test_submit_does_not_require_complement_when_number_is_filled(): void
    {
        Livewire::test(Checkout::class)
            ->set('deliveryOptionId', $this->deliveryOption->id)
            ->call('revealManualAddress')
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('street', 'Rua das Flores')
            ->set('number', '123')
            ->set('neighborhood', 'Centro')
            ->set('complement', '')
            ->set('payments.0.payment_option_id', PaymentOption::first()->id)
            ->call('submit')
            ->assertHasNoErrors(['complement']);

        $this->assertSame(1, Order::count());
    }

    public function test_submit_accepts_sn_as_number_when_complement_is_filled(): void
    {
        Livewire::test(Checkout::class)
            ->set('deliveryOptionId', $this->deliveryOption->id)
            ->call('revealManualAddress')
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('street', 'Rua das Flores')
            ->set('number', 'S/N')
            ->set('complement', 'Em frente à padaria')
            ->set('neighborhood', 'Centro')
            ->set('payments.0.payment_option_id', PaymentOption::first()->id)
            ->call('submit')
            ->assertHasNoErrors();

        $order = Order::first();
        $this->assertSame('S/N', $order->delivery_number);
        $this->assertSame('Em frente à padaria', $order->delivery_complement);
    }

    public function test_submit_resolves_fee_from_matched_delivery_zone(): void
    {
        $zone = DeliveryZone::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Centro',
            'delivery_fee' => 5.50,
        ]);
        $zone->neighborhoods()->create(['neighborhood' => 'Centro']);

        Livewire::test(Checkout::class)
            ->set('deliveryOptionId', $this->deliveryOption->id)
            ->call('revealManualAddress')
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('street', 'Rua das Flores')
            ->set('number', '123')
            ->set('neighborhood', 'Centro')
            ->set('payments.0.payment_option_id', PaymentOption::first()->id)
            ->call('submit')
            ->assertHasNoErrors();

        $order = Order::first();
        $this->assertSame('5.50', $order->delivery_fee);
        $this->assertSame($zone->id, $order->delivery_zone_id);
        $this->assertFalse($order->is_unlisted_neighborhood);
    }

    public function test_submit_is_blocked_when_neighborhood_is_not_served(): void
    {
        DeliveryZone::create(['tenant_id' => $this->tenant->id, 'name' => 'Centro', 'delivery_fee' => 5]);
        $this->tenant->update(['serves_unlisted_neighborhoods' => false]);

        Livewire::test(Checkout::class)
            ->set('deliveryOptionId', $this->deliveryOption->id)
            ->call('revealManualAddress')
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('street', 'Rua das Flores')
            ->set('number', '123')
            ->set('neighborhood', 'Bairro Fantasma')
            ->set('payments.0.payment_option_id', PaymentOption::first()->id)
            ->call('submit')
            ->assertSet('errorMessage', 'A entrega não está disponível para o bairro informado.');

        $this->assertSame(0, Order::count());
    }

    /**
     * RN-37 caso 2 (20/08/2026): taxa de bairro não configurado é somada à
     * taxa normal da opção de entrega, não a substitui — o checkout mostra
     * o detalhamento pro cliente antes de confirmar (RF-39).
     */
    public function test_submit_sums_base_fee_and_unlisted_surcharge_and_checkout_shows_the_breakdown(): void
    {
        DeliveryZone::create(['tenant_id' => $this->tenant->id, 'name' => 'Centro', 'delivery_fee' => 5]);
        $this->tenant->update(['serves_unlisted_neighborhoods' => true, 'unlisted_neighborhood_fee' => 15]);

        Livewire::test(Checkout::class)
            ->set('deliveryOptionId', $this->deliveryOption->id) // taxa normal: R$ 8
            ->call('revealManualAddress')
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('street', 'Rua das Flores')
            ->set('number', '123')
            ->set('neighborhood', 'Bairro Fantasma')
            ->set('payments.0.payment_option_id', PaymentOption::first()->id)
            ->assertSee('Taxa de entrega')
            ->assertSee('Taxa de área não mapeada')
            ->assertSee('23,00') // 8 (taxa normal) + 15 (bairro não configurado)
            ->call('submit')
            ->assertHasNoErrors();

        $order = Order::first();
        $this->assertSame('23.00', $order->delivery_fee);
        $this->assertTrue($order->is_unlisted_neighborhood);
    }

    public function test_address_section_is_hidden_and_fee_resolution_is_skipped_for_options_that_do_not_require_address(): void
    {
        $pickup = DeliveryOption::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Retirada',
            'requires_address' => false,
            'delivery_fee' => 0,
        ]);

        Livewire::test(Checkout::class)
            ->set('deliveryOptionId', $pickup->id)
            ->assertDontSee('Endereço de entrega')
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('payments.0.payment_option_id', PaymentOption::first()->id)
            ->call('submit')
            ->assertHasNoErrors();

        $order = Order::first();
        $this->assertSame($pickup->id, $order->delivery_option_id);
        $this->assertNull($order->delivery_zone_id);
        $this->assertNull($order->delivery_neighborhood);
        $this->assertSame('0.00', $order->delivery_fee);
    }

    public function test_phone_lookup_runs_automatically_once_number_looks_complete_without_needing_blur(): void
    {
        Client::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cliente Existente',
            'phone' => '11999990000',
        ]);

        Livewire::test(Checkout::class)
            ->set('phone', '1199999000') // 10 dígitos ainda incompleto (celular tem 11)
            ->assertSet('clientFound', false)
            ->set('phone', '11999990000') // 11º dígito digitado — dispara a busca sozinho
            ->assertSet('clientFound', true)
            ->assertSet('name', 'Cliente Existente');
    }

    public function test_delivery_fee_hint_is_hidden_when_option_fee_is_zero(): void
    {
        $this->deliveryOption->update(['delivery_fee' => 0]);

        Livewire::test(Checkout::class)
            ->assertDontSee('Taxa conforme o endereço');
    }

    public function test_delivery_fee_hint_is_shown_when_option_fee_is_greater_than_zero(): void
    {
        Livewire::test(Checkout::class)
            ->assertSee('Taxa conforme o endereço');
    }

    public function test_delivery_options_hidden_from_menu_are_not_offered_at_checkout(): void
    {
        $hidden = DeliveryOption::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Opção descontinuada',
            'show_in_menu' => false,
            'delivery_fee' => 3,
        ]);

        Livewire::test(Checkout::class)
            ->assertSee($this->deliveryOption->name)
            ->assertDontSee($hidden->name);
    }
}
