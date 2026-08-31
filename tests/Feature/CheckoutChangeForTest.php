<?php

namespace Tests\Feature;

use App\Livewire\Checkout;
use App\Models\BusinessHour;
use App\Models\Category;
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
 * Troco por parcela de pagamento: o campo de troco só aparece quando a
 * forma escolhida é dinheiro (RN — "se for dinheiro, informa o troco se
 * necessário"), é opcional (em branco = sem troco), e o valor digitado em
 * formato BRL ("1.234,56") é convertido pra float antes de gravar o pedido.
 */
class CheckoutChangeForTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private PaymentOption $cash;

    private PaymentOption $card;

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

        $this->cash = PaymentOption::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Dinheiro',
            'is_cash' => true,
        ]);

        $this->card = PaymentOption::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cartão',
            'is_cash' => false,
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pizzas',
            'display_order' => 1,
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Calabresa',
            'price' => 40,
        ]);

        Cart::addSimple($product->id);
    }

    public function test_first_payment_line_is_prefilled_with_the_order_total(): void
    {
        // Carrinho tem só a Calabresa (R$ 40), sem entrega — total R$ 40.
        Livewire::test(Checkout::class)
            ->assertSet('payments.0.amount', '40,00');
    }

    public function test_change_field_only_shows_up_when_cash_is_selected(): void
    {
        Livewire::test(Checkout::class)
            ->call('selectPaymentOptionForLine', 0, $this->cash->id)
            ->assertSee('Troco para')
            ->call('selectPaymentOptionForLine', 0, $this->card->id)
            ->assertDontSee('Troco para');
    }

    public function test_submit_succeeds_without_change_for_when_cash_is_selected_and_left_blank(): void
    {
        Livewire::test(Checkout::class)
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->call('selectPaymentOptionForLine', 0, $this->cash->id)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(1, Order::count());
        $this->assertNull(Order::first()->payments->first()->change_for);
    }

    public function test_submit_does_not_require_change_for_when_card_is_selected(): void
    {
        Livewire::test(Checkout::class)
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->call('selectPaymentOptionForLine', 0, $this->card->id)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(1, Order::count());
        $this->assertNull(Order::first()->payments->first()->change_for);
    }

    public function test_submit_parses_brl_formatted_change_for_into_a_float(): void
    {
        Livewire::test(Checkout::class)
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->call('selectPaymentOptionForLine', 0, $this->cash->id)
            ->set('payments.0.change_for', '1.234,56')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame('1234.56', Order::first()->payments->first()->change_for);
    }

    public function test_no_change_option_sends_zero(): void
    {
        Livewire::test(Checkout::class)
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->call('selectPaymentOptionForLine', 0, $this->cash->id)
            ->set('payments.0.change_for', '0,00')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame('0.00', Order::first()->payments->first()->change_for);
    }

    /**
     * Sempre que a soma digitada ainda for menor que o total (R$ 40 no
     * carrinho do setUp), a próxima linha em branco recebe o saldo restante
     * automaticamente — poupa o cliente de fazer conta de cabeça ao dividir
     * o pagamento em mais de uma forma.
     */
    public function test_editing_a_payment_amount_autofills_the_remaining_balance_on_the_next_blank_line(): void
    {
        Livewire::test(Checkout::class)
            ->call('addPaymentLine')
            ->set('payments.0.amount', '10,00')
            ->assertSet('payments.1.amount', '30,00');
    }

    public function test_adding_a_payment_line_prefills_it_with_the_remaining_balance(): void
    {
        Livewire::test(Checkout::class)
            ->set('payments.0.amount', '15,00')
            ->call('addPaymentLine')
            ->assertSet('payments.1.amount', '25,00');
    }
}
