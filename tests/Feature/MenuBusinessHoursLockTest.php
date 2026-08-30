<?php

namespace Tests\Feature;

use App\Livewire\Menu;
use App\Models\Category;
use App\Models\FlavorQuantityOption;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Cart;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RN-23: turno fechado bloqueia o carrinho já na escolha do primeiro item
 * (não só no checkout) — mesmo padrão UX do Pizzaria-App. Este arquivo não
 * cadastra nenhum BusinessHour de propósito: sem turno cadastrado, a loja
 * está sempre fechada (App\Actions\Menu\CheckBusinessHours).
 */
class MenuBusinessHoursLockTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Pizzaria Teste',
            'slug' => 'pizzaria-teste',
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);

        $this->category = Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Pizzas',
            'display_order' => 1,
            'show_in_menu' => true,
            'allows_flavors' => true,
        ]);

        FlavorQuantityOption::create([
            'tenant_id' => $tenant->id,
            'category_id' => $this->category->id,
            'label' => 'Sabor único',
            'flavor_count' => 1,
            'display_order' => 1,
        ]);

        $this->product = Product::create([
            'tenant_id' => $tenant->id,
            'category_id' => $this->category->id,
            'name' => 'Calabresa',
            'price' => 40,
        ]);
    }

    public function test_add_to_cart_is_blocked_and_opens_the_cart_drawer_when_closed(): void
    {
        Livewire::test(Menu::class)
            ->call('addToCart', $this->product->id)
            ->assertSet('showCart', true);

        $this->assertCount(0, Cart::items());
    }

    public function test_start_combo_is_blocked_and_opens_the_cart_drawer_instead_of_the_flavor_modal(): void
    {
        Livewire::test(Menu::class)
            ->call('startCombo', $this->category->id, $this->product->id)
            ->assertSet('showCart', true)
            ->assertSet('comboBuilder.category_id', null);
    }

    public function test_confirm_combo_does_not_add_when_shift_closes_while_modal_is_open(): void
    {
        // Simula o turno já ter fechado enquanto o modal estava aberto: o
        // estado do comboBuilder é montado direto (sem passar por
        // startCombo, que já bloquearia antes) pra isolar a defesa em
        // confirmCombo().
        Livewire::test(Menu::class)
            ->set('comboBuilder', [
                'category_id' => $this->category->id,
                'quantity_option_id' => FlavorQuantityOption::first()->id,
                'required_count' => 1,
                'flavor_ids' => [$this->product->id],
                'step' => 'flavors',
            ])
            ->call('confirmCombo')
            ->assertSet('showCart', true)
            ->assertSet('comboBuilder.category_id', null);

        $this->assertCount(0, Cart::items());
    }
}
