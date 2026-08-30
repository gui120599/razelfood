<?php

namespace Tests\Feature\Orders;

use App\Filament\Tenant\Livewire\Orders\FulfillmentPicker;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Auto-preenchimento do saldo restante nas linhas de pagamento do painel
 * interno — mesma regra do Checkout público (CheckoutChangeForTest). O total
 * é uma prop `#[Reactive]` passada pela AttendOrder a cada render; mudá-la
 * dispara `updatedTotal()` (aqui simulado com `->set('total', ...)`).
 */
class FulfillmentPickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CurrentTenant::set(Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'whatsapp_number' => '5511999999999',
        ]));
    }

    public function test_mount_prefills_the_first_line_with_the_order_total(): void
    {
        Livewire::test(FulfillmentPicker::class, ['total' => 100.0])
            ->assertSet('payments.0.amount', '100,00');
    }

    public function test_adding_a_payment_line_prefills_it_with_the_remaining_balance(): void
    {
        Livewire::test(FulfillmentPicker::class, ['total' => 100.0])
            ->set('payments.0.amount', '30,00')
            ->call('addPaymentLine')
            ->assertSet('payments.1.amount', '70,00');
    }

    public function test_editing_a_payment_amount_autofills_the_remaining_balance_on_the_next_blank_line(): void
    {
        Livewire::test(FulfillmentPicker::class, ['total' => 100.0])
            ->call('addPaymentLine')
            ->set('payments.0.amount', '40,00')
            ->assertSet('payments.1.amount', '60,00');
    }

    public function test_editing_the_first_amount_fills_the_remaining_balance_on_a_blank_line(): void
    {
        Livewire::test(FulfillmentPicker::class, ['total' => 100.0])
            ->call('addPaymentLine')
            ->set('payments.0.amount', '20,00')
            ->assertSet('payments.1.amount', '80,00');
    }
}
