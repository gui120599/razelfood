<?php

namespace Tests\Feature;

use App\Livewire\Checkout;
use App\Models\BusinessHour;
use App\Models\Category;
use App\Models\City;
use App\Models\DeliveryOption;
use App\Models\DeliveryZone;
use App\Models\Neighborhood;
use App\Models\Order;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\State;
use App\Models\Tenant;
use App\Support\Cart;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Modo restrito de endereço no checkout (tenant.allow_free_form_address =
 * false + setores cadastrados): estado/cidade/bairro viram selects filtrados
 * pelo que o tenant atende, e o bairro sai do catálogo global da cidade.
 */
class CheckoutRestrictedAddressTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private DeliveryOption $deliveryOption;

    private City $saoPaulo;

    private City $campinas;

    private DeliveryZone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        Cart::clear();

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'whatsapp_number' => '5511999999999',
            'allow_free_form_address' => false,
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

        $this->deliveryOption = DeliveryOption::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Entrega padrão',
            'delivery_fee' => 8,
            'requires_address' => true,
        ]);

        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);

        PaymentOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Cartão', 'is_cash' => false]);

        // Catálogo global.
        $state = State::create(['name' => 'São Paulo', 'uf' => 'SP', 'ibge_code' => 35]);
        $this->saoPaulo = City::create(['state_id' => $state->id, 'name' => 'São Paulo', 'ibge_code' => 3550308]);
        $this->campinas = City::create(['state_id' => $state->id, 'name' => 'Campinas', 'ibge_code' => 3509502]);
        Neighborhood::create(['city_id' => $this->saoPaulo->id, 'name' => 'Vila Mariana']);
        Neighborhood::create(['city_id' => $this->saoPaulo->id, 'name' => 'Bela Vista']);
        Neighborhood::create(['city_id' => $this->campinas->id, 'name' => 'Cambuí']);

        // Setor de entrega atendendo só um bairro de São Paulo.
        $this->zone = DeliveryZone::create(['tenant_id' => $this->tenant->id, 'name' => 'Zona Sul', 'delivery_fee' => 6.50]);
        $this->zone->neighborhoods()->create([
            'city_id' => $this->saoPaulo->id,
            'city' => 'São Paulo',
            'neighborhood' => 'Vila Mariana',
        ]);

        Cart::addSimple($product->id);
    }

    public function test_state_and_city_options_are_limited_to_served_cities(): void
    {
        $component = Livewire::test(Checkout::class)
            ->set('deliveryOptionId', $this->deliveryOption->id)
            ->call('revealManualAddress');

        $this->assertTrue($component->instance()->addressIsRestricted);
        $this->assertEqualsCanonicalizing(['SP'], $component->instance()->servedStates->pluck('uf')->all());
        $this->assertEqualsCanonicalizing(['São Paulo'], $component->instance()->servedCities->pluck('name')->all());
    }

    public function test_neighborhood_options_are_the_full_city_catalog(): void
    {
        $component = Livewire::test(Checkout::class)
            ->set('deliveryOptionId', $this->deliveryOption->id)
            ->call('revealManualAddress')
            ->assertSee('Entregamos apenas nas cidades e bairros já cadastrados')
            ->assertSee('Buscar cidade')
            ->set('state', 'SP')
            ->set('cityId', $this->saoPaulo->id)
            ->assertSeeHtml('Vila Mariana');

        $this->assertSame('São Paulo', $component->instance()->city);
        $this->assertEqualsCanonicalizing(['Vila Mariana', 'Bela Vista'], array_keys($component->instance()->neighborhoodOptions));
    }

    public function test_free_form_mode_does_not_show_the_restricted_notice_or_search(): void
    {
        $this->tenant->update(['allow_free_form_address' => true]);

        Livewire::test(Checkout::class)
            ->set('deliveryOptionId', $this->deliveryOption->id)
            ->call('revealManualAddress')
            ->assertDontSee('Entregamos apenas nas cidades e bairros já cadastrados')
            ->assertDontSee('Buscar cidade');
    }

    public function test_choosing_a_served_neighborhood_resolves_the_zone_fee(): void
    {
        Livewire::test(Checkout::class)
            ->set('deliveryOptionId', $this->deliveryOption->id)
            ->call('revealManualAddress')
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('state', 'SP')
            ->set('cityId', $this->saoPaulo->id)
            ->set('neighborhood', 'Vila Mariana')
            ->set('street', 'Rua das Flores')
            ->set('number', '123')
            ->set('payments.0.payment_option_id', PaymentOption::first()->id)
            ->call('submit')
            ->assertHasNoErrors();

        $order = Order::first();
        $this->assertSame('6.50', $order->delivery_fee);
        $this->assertSame($this->zone->id, $order->delivery_zone_id);
        $this->assertSame('Vila Mariana', $order->delivery_neighborhood);
    }

    public function test_catalog_neighborhood_outside_any_zone_is_blocked_on_submit(): void
    {
        $this->tenant->update(['serves_unlisted_neighborhoods' => false]);

        Livewire::test(Checkout::class)
            ->set('deliveryOptionId', $this->deliveryOption->id)
            ->call('revealManualAddress')
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('state', 'SP')
            ->set('cityId', $this->saoPaulo->id)
            ->set('neighborhood', 'Bela Vista')
            ->set('street', 'Rua das Flores')
            ->set('number', '123')
            ->set('payments.0.payment_option_id', PaymentOption::first()->id)
            ->call('submit')
            ->assertSet('errorMessage', 'A entrega não está disponível para o bairro informado.');

        $this->assertSame(0, Order::count());
    }

    public function test_changing_state_resets_city_and_neighborhood(): void
    {
        Livewire::test(Checkout::class)
            ->set('deliveryOptionId', $this->deliveryOption->id)
            ->call('revealManualAddress')
            ->set('state', 'SP')
            ->set('cityId', $this->saoPaulo->id)
            ->set('neighborhood', 'Vila Mariana')
            ->set('state', 'SP')
            ->assertSet('cityId', null)
            ->assertSet('city', null)
            ->assertSet('neighborhood', null);
    }

    public function test_free_form_tenant_keeps_text_inputs(): void
    {
        $this->tenant->update(['allow_free_form_address' => true]);

        $component = Livewire::test(Checkout::class)
            ->set('deliveryOptionId', $this->deliveryOption->id)
            ->call('revealManualAddress');

        $this->assertFalse($component->instance()->addressIsRestricted);
    }
}
