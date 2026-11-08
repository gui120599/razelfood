<?php

namespace Tests\Feature\Orders;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Livewire\Orders\ClientLookup;
use App\Filament\Tenant\Livewire\Orders\FulfillmentPicker;
use App\Filament\Tenant\Livewire\Orders\ProductCatalogSelector;
use App\Filament\Tenant\Pages\Orders\AttendOrder;
use App\Models\Category;
use App\Models\DeliveryOption;
use App\Models\FlavorQuantityOption;
use App\Models\Order;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Visual da tela "Pedido" (Fase 1.S.1a), copiada do Pizzaria-App: título, chip de tipo de
 * atendimento, catálogo em linhas, endereço só quando a entrega exige e ausência do que
 * o RazelFood não tem (cupom, maquininha, cortesia).
 */
class AttendOrderScreenTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

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

        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole('Atendente');
        $this->actingAs($user);

        PaymentOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Cartão', 'is_cash' => false]);
        $this->category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create($attributes + [
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'name' => 'Refrigerante',
            'price' => 8,
        ]);
    }

    public function test_page_shows_the_title_and_the_service_type_chip_following_the_delivery_option(): void
    {
        $delivery = DeliveryOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Entrega em casa', 'requires_address' => true]);

        Livewire::test(AttendOrder::class)
            ->assertSee('Tipo de atendimento')
            ->assertSee('Defina a entrega')
            ->assertSet('currentOrder', null)
            ->call('syncFulfillmentData', ['delivery_option_id' => $delivery->id, 'payments' => []])
            ->assertSee('Entrega em casa')
            ->assertDontSee('Defina a entrega');
    }

    public function test_page_has_no_coupon_courtesy_or_card_machine_controls(): void
    {
        Livewire::test(AttendOrder::class)
            ->assertSee('Resumo financeiro')
            ->assertSee('Criar pedido')
            ->assertDontSee('Cupom')
            ->assertDontSee('Cortesia')
            ->assertDontSee('maquininha');
    }

    public function test_catalog_lists_products_as_rows_with_price_and_stock_text(): void
    {
        $this->makeProduct(['name' => 'Suco', 'price' => 12, 'controls_stock' => true, 'stock_quantity' => 7, 'commercial_unit' => 'UN']);

        Livewire::test(ProductCatalogSelector::class)
            ->assertSee('Suco')
            ->assertSee('R$ 12,00')
            ->assertSee('7 UN em estoque')
            ->assertSee('Bebidas');
    }

    public function test_catalog_shows_the_original_price_struck_through_with_promo_badge(): void
    {
        $this->makeProduct(['name' => 'Suco', 'price' => 12, 'promotional_price' => 9]);

        Livewire::test(ProductCatalogSelector::class)
            ->assertSee('PROMO')
            ->assertSee('R$ 12,00')
            ->assertSee('R$ 9,00');
    }

    public function test_catalog_hides_products_not_shown_in_the_waiter_menu_and_other_tenants(): void
    {
        $this->makeProduct(['name' => 'Visível']);
        $this->makeProduct(['name' => 'Escondido do garçom', 'show_in_waiter_menu' => false]);

        $other = Tenant::create(['name' => 'Outro', 'slug' => 'outro', 'status' => TenantStatus::Active, 'whatsapp_number' => '5511888888888']);
        CurrentTenant::set($other);
        $otherCategory = Category::create(['tenant_id' => $other->id, 'name' => 'Outra', 'display_order' => 1]);
        Product::create(['tenant_id' => $other->id, 'category_id' => $otherCategory->id, 'name' => 'Produto Alheio', 'price' => 5]);
        CurrentTenant::set($this->tenant);

        Livewire::test(ProductCatalogSelector::class)
            ->assertSee('Visível')
            ->assertDontSee('Escondido do garçom')
            ->assertDontSee('Produto Alheio');
    }

    public function test_client_address_fields_only_appear_when_the_delivery_requires_it(): void
    {
        Livewire::test(ClientLookup::class, ['requiresAddress' => false])
            ->assertSee('Pedido sem cliente cadastrado')
            ->assertDontSee('Endereço de entrega');

        Livewire::test(ClientLookup::class, ['requiresAddress' => true])
            ->assertSee('Endereço de entrega')
            ->assertSeeHtml('placeholder="CEP"');
    }

    public function test_fulfillment_picker_renders_a_select_with_only_this_tenants_delivery_options(): void
    {
        DeliveryOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Retirada no balcão', 'requires_address' => false]);

        $other = Tenant::create(['name' => 'Outro', 'slug' => 'outro', 'status' => TenantStatus::Active, 'whatsapp_number' => '5511888888888']);
        DeliveryOption::create(['tenant_id' => $other->id, 'name' => 'Opção Alheia', 'requires_address' => false]);

        Livewire::test(FulfillmentPicker::class, ['total' => 10.0])
            ->assertSeeHtml('wire:change="selectDeliveryOption($event.target.value)"')
            ->assertSee('Retirada no balcão')
            ->assertDontSee('Opção Alheia');
    }

    public function test_fulfillment_picker_clears_the_choice_when_the_empty_option_is_selected(): void
    {
        $option = DeliveryOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Retirada', 'requires_address' => false]);

        Livewire::test(FulfillmentPicker::class, ['total' => 10.0])
            ->call('selectDeliveryOption', $option->id)
            ->assertSet('deliveryOptionId', $option->id)
            ->call('selectDeliveryOption', '')
            ->assertSet('deliveryOptionId', null);
    }

    public function test_cart_renders_simple_and_half_and_half_lines(): void
    {
        $simple = $this->makeProduct(['name' => 'Suco', 'price' => 12]);

        $pizzas = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 2, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $pizzas->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 1]);
        $a = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $pizzas->id, 'name' => 'Calabresa', 'price' => 40]);
        $b = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $pizzas->id, 'name' => 'Marguerita', 'price' => 50]);

        Livewire::test(AttendOrder::class)
            ->assertSee('Nenhum item adicionado ainda.')
            ->call('addSimpleItem', $simple->id)
            ->call('addConfirmedLine', [
                'type' => 'combo', 'product_id' => $a->id, 'flavor_ids' => [$a->id, $b->id], 'quantity' => 1, 'note' => null,
            ])
            ->assertDontSee('Nenhum item adicionado ainda.')
            ->assertSee('Suco')
            ->assertSee('MEIA Calabresa')
            ->assertSee('MEIA Marguerita');
    }

    public function test_fulfillment_picker_ignores_a_delivery_option_that_is_not_in_its_list(): void
    {
        $other = Tenant::create(['name' => 'Outro', 'slug' => 'outro', 'status' => TenantStatus::Active, 'whatsapp_number' => '5511888888888']);
        $foreign = DeliveryOption::create(['tenant_id' => $other->id, 'name' => 'Opção Alheia', 'requires_address' => false]);

        Livewire::test(FulfillmentPicker::class, ['total' => 10.0])
            ->call('selectDeliveryOption', $foreign->id)
            ->assertSet('deliveryOptionId', null);
    }

    public function test_payment_line_can_go_back_to_the_empty_option(): void
    {
        $option = PaymentOption::first();

        Livewire::test(FulfillmentPicker::class, ['total' => 10.0])
            ->call('selectPaymentOptionForLine', 0, $option->id)
            ->assertSet('payments.0.payment_option_id', $option->id)
            ->call('selectPaymentOptionForLine', 0, '')
            ->assertSet('payments.0.payment_option_id', null);
    }

    public function test_address_typed_before_switching_to_pickup_is_not_saved_on_the_order(): void
    {
        $pickup = DeliveryOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Retirada', 'requires_address' => false]);
        $product = $this->makeProduct();

        Livewire::test(AttendOrder::class)
            ->call('addSimpleItem', $product->id)
            ->call('syncClientData', [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => '01001000',
                'street' => 'Rua Velha', 'number' => '10', 'complement' => null,
                'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP',
            ])
            ->call('syncFulfillmentData', [
                'delivery_option_id' => $pickup->id,
                'payments' => [['payment_option_id' => PaymentOption::first()->id, 'amount' => null, 'change_for' => null]],
            ])
            ->call('save')
            ->assertRedirect();

        $order = Order::firstOrFail();
        $this->assertEmpty($order->delivery_address);
    }
}
