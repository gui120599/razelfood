<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\AddSalePayment;
use App\Actions\Sales\AddStandaloneItem;
use App\Actions\Sales\AttachOrderItems;
use App\Actions\Sales\FinalizeOpenSale;
use App\Actions\Sales\StartSale;
use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\SaleStatus;
use App\Filament\Tenant\Livewire\Sales\SaleProductSelector;
use App\Filament\Tenant\Pages\Sales\CloseAccount;
use App\Filament\Tenant\Pages\Sales\NewSale;
use App\Filament\Tenant\Pages\Sales\PendingAccounts;
use App\Filament\Tenant\Resources\Sales\Pages\ListSales;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Nova Venda (Fase 1.R.2): a tela copiada do Pizzaria-App, ligada às Actions do rascunho. Papéis e
 * permissões reais; cada ação grava direto no banco (nada só na tela).
 */
class NewSalePageTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesNfceFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private PaymentOption $cash;

    private PaymentOption $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        $this->tenant = $this->makeSaleTenant('lazzopizza');
        $this->actAsTenant($this->tenant);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(SeedDefaultTenantRoles::class)($this->tenant);

        $this->cash = $this->makePaymentOption($this->tenant, 'Dinheiro', isCash: true);
        $this->card = $this->makePaymentOption($this->tenant, 'Cartão');
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function openSale(): Sale
    {
        return Sale::query()->where('status', SaleStatus::Open)->latest('id')->firstOrFail();
    }

    private function order(float $fee = 0): Order
    {
        return $this->makeNfceOrder($this->tenant, [
            ['product' => $this->makeProduct($this->tenant, 'Pizza '.uniqid(), 40)],
            ['product' => $this->makeProduct($this->tenant, 'Refri '.uniqid(), 8), 'quantity' => 2],
        ], $fee);
    }

    // ---- Acesso ----------------------------------------------------------

    public function test_the_screen_renders_with_the_two_tabs_and_without_the_features_that_do_not_exist_yet(): void
    {
        $this->actingAsRole('Caixa');

        Livewire::test(NewSale::class)
            ->assertOk()
            ->assertSee('Pedidos')
            ->assertSee('Produtos')
            ->assertSee('Nova venda')
            ->assertSee('Nenhum item lançado ainda.')
            ->assertSee('Nenhum cliente vinculado')
            ->assertSee('Desconto percentual')
            ->assertSee('Frete')
            ->assertSee('Nenhum pagamento lançado.')
            ->assertSee('Finalizar venda')
            ->assertDontSee('Mesas')
            ->assertDontSee('Pendentes')
            ->assertDontSee('Stone')
            ->assertDontSee('Cortesia');
    }

    public function test_only_roles_with_manage_sales_reach_the_screen_and_only_with_the_pdv(): void
    {
        foreach (['Admin' => true, 'Gerente' => true, 'Caixa' => true, 'Atendente' => false] as $role => $allowed) {
            $this->actingAsRole($role);
            $this->assertSame($allowed, NewSale::canAccess(), $role);
            $this->assertSame($allowed, NewSale::shouldRegisterNavigation(), $role);
        }

        $plain = $this->makeSaleTenant('semfeature', withPdv: false);
        $this->actAsTenant($plain);
        app(SeedDefaultTenantRoles::class)($plain);
        $admin = User::factory()->create(['tenant_id' => $plain->id]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $this->assertFalse(NewSale::canAccess());
        Livewire::test(NewSale::class)->assertForbidden();
    }

    public function test_the_old_urls_redirect_to_the_new_screen(): void
    {
        $this->actingAsRole('Caixa');

        Livewire::test(PendingAccounts::class)->assertRedirect(NewSale::getUrl());
        Livewire::test(CloseAccount::class)->assertRedirect(NewSale::getUrl());
        Livewire::withQueryParams(['orders' => '3,4'])->test(CloseAccount::class)->assertRedirect(NewSale::getUrl().'?orders=3%2C4');
    }

    public function test_the_requested_orders_of_the_old_url_are_launched_in_a_new_draft(): void
    {
        $this->actingAsRole('Caixa');
        $order = $this->order();

        $component = Livewire::withQueryParams(['orders' => (string) $order->id])->test(NewSale::class);

        $sale = $this->openSale();
        $component->assertRedirect(NewSale::getUrl(['sale' => $sale->id]));
        $this->assertSame(2, OrderItem::where('sale_id', $sale->id)->count());

        // F5 na URL nova retoma o rascunho (e não relança `?orders=`, que viraria "indisponível").
        Livewire::test(NewSale::class, ['sale' => $sale->id])->assertSet('saleId', $sale->id)->assertSee('R$ 56,00');
        $this->assertSame(1, Sale::count());
    }

    // ---- Produtos ---------------------------------------------------------

    public function test_the_product_selector_filters_by_search_and_category_and_dispatches_the_click(): void
    {
        $this->actingAsRole('Caixa');
        $pizza = $this->makeProduct($this->tenant, 'Calabresa', 40);
        $this->makeProduct($this->tenant, 'Refrigerante', 8);

        Livewire::test(SaleProductSelector::class)
            ->assertSee('Calabresa')
            ->assertSee('Refrigerante')
            ->set('search', 'Calab')
            ->assertSee('Calabresa')
            ->assertDontSee('Refrigerante')
            ->call('selectProduct', $pizza->id)
            ->assertDispatched('product-selected', productId: $pizza->id);
    }

    public function test_the_selector_never_lists_products_of_another_tenant(): void
    {
        $this->actingAsRole('Caixa');
        $other = $this->makeSaleTenant('emporiodapizza');
        $this->makeProduct($other, 'Produto Alheio', 10);
        $this->makeProduct($this->tenant, 'Produto Meu', 10);

        Livewire::test(SaleProductSelector::class)->assertSee('Produto Meu')->assertDontSee('Produto Alheio');
    }

    public function test_the_first_product_click_creates_the_draft_and_merges_repeated_clicks(): void
    {
        $this->actingAsRole('Caixa');
        $pizza = $this->makeProduct($this->tenant, 'Calabresa', 40);
        $this->assertSame(0, Sale::count());

        Livewire::test(NewSale::class)
            ->set('activeTab', 'products')
            ->dispatch('product-selected', productId: $pizza->id)
            ->dispatch('product-selected', productId: $pizza->id)
            ->assertSee('Calabresa')
            ->assertSee('R$ 80,00');

        $sale = $this->openSale();
        $this->assertSame(1, Sale::count());
        $this->assertSame(2, SaleItem::where('sale_id', $sale->id)->sole()->quantity);
        $this->assertSame('80.00', $sale->total);
    }

    public function test_quantity_buttons_and_removal_write_to_the_database(): void
    {
        $this->actingAsRole('Caixa');
        $pizza = $this->makeProduct($this->tenant, 'Calabresa', 40);
        $component = Livewire::test(NewSale::class)->dispatch('product-selected', productId: $pizza->id);
        $item = SaleItem::sole();

        $component->call('changeQuantity', $item->id, 3);
        $this->assertSame('120.00', $this->openSale()->total);

        $component->call('changeQuantity', $item->id, 0);
        $this->assertSame(0, SaleItem::count());
        $this->assertSame('0.00', $this->openSale()->total);
    }

    public function test_a_stock_shortage_shows_an_error_and_keeps_the_draft_intact(): void
    {
        $this->actingAsRole('Caixa');
        $wine = $this->makeProduct($this->tenant, 'Vinho', 40, ['controls_stock' => true, 'stock_quantity' => 1]);

        Livewire::test(NewSale::class)
            ->set('activeTab', 'products')
            ->dispatch('product-selected', productId: $wine->id)
            ->dispatch('product-selected', productId: $wine->id)
            ->assertNotified();

        $this->assertSame(1, SaleItem::sole()->quantity);
    }

    // ---- Pedidos ----------------------------------------------------------

    public function test_the_orders_tab_lists_pending_orders_and_launches_them_item_by_item(): void
    {
        $this->actingAsRole('Caixa');
        $order = $this->order(fee: 5);
        [$pizza, $soda] = OrderItem::where('order_id', $order->id)->orderBy('id')->pluck('id')->all();

        $component = Livewire::test(NewSale::class)
            ->assertSee("Pedido #{$order->id}")
            ->call('toggleOrderItem', $pizza)
            ->assertSee('R$ 40,00');

        $sale = $this->openSale();
        $this->assertSame($sale->id, OrderItem::find($pizza)->sale_id);
        $this->assertNull(OrderItem::find($soda)->sale_id);

        $component->call('toggleOrderItem', $soda);
        $this->assertSame('61.00', $sale->fresh()->total, '40 + 2×8 + frete 5');

        $component->call('toggleOrderItem', $pizza);
        $this->assertNull(OrderItem::find($pizza)->sale_id, 'Clicar de novo devolve o item.');
    }

    public function test_launching_and_removing_a_whole_order(): void
    {
        $this->actingAsRole('Caixa');
        $order = $this->order();

        $component = Livewire::test(NewSale::class)->call('launchOrder', $order->id);
        $this->assertSame(2, OrderItem::where('sale_id', $this->openSale()->id)->count());

        $component->call('removeOrder', $order->id);
        $this->assertSame(0, OrderItem::where('sale_id', $this->openSale()->id)->count());
    }

    public function test_an_item_in_another_sale_is_shown_as_taken_and_cannot_be_launched(): void
    {
        $this->actingAsRole('Caixa');
        $order = $this->order();
        $other = app(StartSale::class)();
        app(AttachOrderItems::class)($other, $order->id, [OrderItem::where('order_id', $order->id)->orderBy('id')->value('id')]);
        $item = OrderItem::where('order_id', $order->id)->orderBy('id')->first();

        Livewire::test(NewSale::class)
            ->assertSee("rascunho #{$other->id}")
            ->call('toggleOrderItem', $item->id)
            ->assertNotified();

        $this->assertSame($other->id, $item->fresh()->sale_id);
    }

    public function test_the_orders_tab_never_lists_orders_of_another_tenant_and_the_search_filters(): void
    {
        $this->actingAsRole('Caixa');
        $mine = $this->order();
        $other = $this->makeSaleTenant('emporiodapizza');
        $foreign = $this->makeNfceOrder($other, [['product' => $this->makeProduct($other, 'Alheia', 40)]]);

        Livewire::test(NewSale::class)
            ->assertSee("Pedido #{$mine->id}")
            ->assertDontSee("Pedido #{$foreign->id}")
            ->set('orderSearch', 'zzz-ninguem')
            ->assertDontSee("Pedido #{$mine->id}")
            ->assertSee('Nenhum pedido pendente.');
    }

    public function test_an_item_of_another_tenants_order_cannot_be_launched_by_forging_the_id(): void
    {
        $this->actingAsRole('Caixa');
        $other = $this->makeSaleTenant('emporiodapizza');
        $foreign = $this->makeNfceOrder($other, [['product' => $this->makeProduct($other, 'Alheia', 40)]]);
        $itemId = OrderItem::where('order_id', $foreign->id)->value('id');

        Livewire::test(NewSale::class)->call('toggleOrderItem', $itemId)->assertNotified();

        $this->assertNull(OrderItem::find($itemId)->sale_id);
    }

    // ---- Cliente, desconto, frete ----------------------------------------

    public function test_discount_and_freight_are_applied_and_shown_in_the_totals(): void
    {
        $this->actingAsRole('Caixa');
        $pizza = $this->makeProduct($this->tenant, 'Pizza', 100);

        Livewire::test(NewSale::class)
            ->dispatch('product-selected', productId: $pizza->id)
            ->call('applyDiscountPercent', '10')
            ->call('updateDeliveryFee', 4)
            ->assertSee('R$ 94,00')
            ->assertSee('Desfazer')
            ->call('clearDiscountPercent')
            ->assertSee('R$ 104,00');

        $this->assertNull($this->openSale()->discount_percent);
    }

    public function test_the_client_is_searched_selected_removed_and_created_on_the_spot(): void
    {
        $this->actingAsRole('Caixa');
        $maria = Client::create(['tenant_id' => $this->tenant->id, 'name' => 'Maria Souza', 'phone' => '11988887777', 'cpf' => '52998224725']);
        $other = $this->makeSaleTenant('emporiodapizza');
        Client::create(['tenant_id' => $other->id, 'name' => 'Maria Alheia', 'phone' => '11977776666']);

        $component = Livewire::test(NewSale::class)
            ->call('openClientModal')
            ->set('clientSearch', 'Maria')
            ->assertSee('Maria Souza')
            ->assertDontSee('Maria Alheia')
            ->set('clientSearch', '529.982')
            ->assertSee('Maria Souza')
            ->call('selectClient', $maria->id)
            ->assertSee('Maria Souza');

        $this->assertSame($maria->id, $this->openSale()->client_id);

        $component->call('removeClient');
        $this->assertNull($this->openSale()->client_id);

        $component->call('openClientModal')
            ->set('adHocName', 'João da Silva')
            ->set('adHocPhone', '(11) 95555-4444')
            ->set('adHocCpf', '529.982.247-25')
            ->call('saveAdHocClient')
            ->assertHasNoErrors();

        $joao = Client::where('name', 'João da Silva')->sole();
        $this->assertSame('11955554444', $joao->phone);
        $this->assertSame($joao->id, $this->openSale()->client_id);
    }

    public function test_the_quick_client_form_validates(): void
    {
        $this->actingAsRole('Caixa');

        Livewire::test(NewSale::class)
            ->call('openClientModal')
            ->set('adHocName', '')
            ->set('adHocPhone', '123')
            ->set('adHocCpf', '111.111.111-11')
            ->call('saveAdHocClient')
            ->assertHasErrors(['adHocName', 'adHocPhone', 'adHocCpf']);

        $this->assertSame(0, Client::count());
    }

    // ---- Pagamentos -------------------------------------------------------

    public function test_a_cash_payment_keeps_the_handed_over_amount_and_previews_the_change(): void
    {
        $this->actingAsRole('Caixa');
        $pizza = $this->makeProduct($this->tenant, 'Pizza', 40);

        Livewire::test(NewSale::class)
            ->dispatch('product-selected', productId: $pizza->id)
            ->call('openPaymentModal')
            ->assertSet('receivedAmount', 40.0)
            ->set('paymentOptionId', $this->cash->id)
            ->set('receivedAmount', 40.0)
            ->set('tenderedAmount', 50.0)
            ->call('registerPayment')
            ->assertSet('paymentModalOpen', false)
            ->assertSee('Dinheiro')
            ->assertSee('R$ 10,00');

        $payment = SalePayment::sole();
        $this->assertSame('50.00', $payment->amount);
        $this->assertSame('0.00', $payment->change_amount, 'O troco só é calculado ao finalizar.');
    }

    public function test_payment_validation_shows_inside_the_modal_and_saves_nothing(): void
    {
        $this->actingAsRole('Caixa');
        $strict = PaymentOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Crédito', 'requires_card_brand' => true, 'requires_authorization_code' => true]);
        $this->makeProduct($this->tenant, 'Pizza', 40);

        Livewire::test(NewSale::class)
            ->call('openPaymentModal')
            ->set('paymentOptionId', $strict->id)
            ->set('receivedAmount', 40.0)
            ->call('registerPayment')
            ->assertHasErrors('receivedAmount')
            ->assertSet('paymentModalOpen', true);

        $this->assertSame(0, SalePayment::count());
    }

    public function test_a_payment_is_edited_and_removed(): void
    {
        $this->actingAsRole('Caixa');
        $component = Livewire::test(NewSale::class)
            ->dispatch('product-selected', productId: $this->makeProduct($this->tenant, 'Pizza', 40)->id)
            ->set('paymentOptionId', $this->cash->id)
            ->set('receivedAmount', 20.0)
            ->call('registerPayment');
        $payment = SalePayment::sole();

        $component->call('editPayment', $payment->id)
            ->assertSet('editingPaymentId', $payment->id)
            ->assertSet('receivedAmount', 20.0)
            ->set('receivedAmount', 30.0)
            ->call('registerPayment');
        $this->assertSame('30.00', $payment->fresh()->amount);

        $component->call('removePayment', $payment->id);
        $this->assertSame(0, SalePayment::count());
    }

    public function test_split_the_bill_creates_equal_payments(): void
    {
        $this->actingAsRole('Caixa');

        Livewire::test(NewSale::class)
            ->dispatch('product-selected', productId: $this->makeProduct($this->tenant, 'Pizza', 90)->id)
            ->call('openSplitModal')
            ->call('splitBill', 3, $this->card->id)
            ->assertSet('splitModalOpen', false);

        $this->assertSame(['30.00', '30.00', '30.00'], SalePayment::orderBy('id')->pluck('amount')->all());
    }

    // ---- Finalizar e cancelar ----------------------------------------------

    public function test_finalizing_closes_the_sale_notifies_and_returns_to_an_empty_screen(): void
    {
        $user = $this->actingAsRole('Caixa');
        $order = $this->order();

        Livewire::test(NewSale::class)
            ->call('launchOrder', $order->id)
            ->dispatch('product-selected', productId: $this->makeProduct($this->tenant, 'Refri', 8)->id)
            ->set('paymentOptionId', $this->cash->id)
            ->set('receivedAmount', 64.0)
            ->call('registerPayment')
            ->call('finalizeSale')
            ->assertHasNoErrors()
            ->assertNotified('Venda #1 finalizada')
            ->assertRedirect(NewSale::getUrl());

        $sale = Sale::sole();
        $this->assertSame(SaleStatus::Finalized, $sale->status);
        $this->assertSame('64.00', $sale->total);
        $this->assertSame($user->id, $sale->finalized_by_user_id);
        $this->assertSame($sale->id, $order->fresh()->sale_id);
    }

    public function test_finalizing_with_insufficient_payment_shows_the_error_and_keeps_the_draft(): void
    {
        $this->actingAsRole('Caixa');

        Livewire::test(NewSale::class)
            ->dispatch('product-selected', productId: $this->makeProduct($this->tenant, 'Pizza', 40)->id)
            ->set('paymentOptionId', $this->card->id)
            ->set('receivedAmount', 30.0)
            ->call('registerPayment')
            ->call('finalizeSale')
            ->assertHasErrors('finalize')
            ->assertSee('Pagamento insuficiente');

        $this->assertSame(SaleStatus::Open, Sale::sole()->status);
    }

    public function test_finalizing_an_empty_screen_does_not_create_anything(): void
    {
        $this->actingAsRole('Caixa');

        Livewire::test(NewSale::class)->call('finalizeSale')->assertHasErrors('finalize');

        $this->assertSame(0, Sale::count());
    }

    public function test_cancelling_requires_a_reason_and_releases_the_reservations(): void
    {
        $this->actingAsRole('Caixa');
        $order = $this->order();

        $component = Livewire::test(NewSale::class)->call('launchOrder', $order->id)->call('openCancelModal');

        $component->set('cancellationNote', '')->call('confirmCancellation')->assertNotified();
        $this->assertSame(SaleStatus::Open, Sale::sole()->status);

        $component->set('cancellationNote', 'Cliente desistiu')->call('confirmCancellation')->assertRedirect(NewSale::getUrl());

        $sale = Sale::sole();
        $this->assertSame(SaleStatus::Cancelled, $sale->status);
        $this->assertSame('Cliente desistiu', $sale->cancellation_note);
        $this->assertFalse($order->fresh()->hasItemsInSale());
    }

    public function test_an_open_sale_is_resumed_by_its_url_and_a_closed_one_is_refused(): void
    {
        $this->actingAsRole('Caixa');
        $pizza = $this->makeProduct($this->tenant, 'Calabresa', 40);
        $sale = app(StartSale::class)();
        app(AddStandaloneItem::class)($sale, $pizza->id);

        Livewire::test(NewSale::class, ['sale' => $sale->id])->assertSet('saleId', $sale->id)->assertSee('Calabresa')->assertSee("Rascunho #{$sale->id}");

        app(AddSalePayment::class)($sale, ['payment_option_id' => $this->cash->id, 'amount' => 40]);
        app(FinalizeOpenSale::class)($sale);

        Livewire::test(NewSale::class, ['sale' => $sale->id])->assertRedirect(NewSale::getUrl());
    }

    public function test_the_sales_list_offers_to_resume_an_open_draft_and_never_for_a_finalized_sale(): void
    {
        $this->actingAsRole('Caixa');
        $draft = app(StartSale::class)();
        app(AddStandaloneItem::class)($draft, $this->makeProduct($this->tenant, 'Pizza', 40)->id);
        $done = app(StartSale::class)();
        app(AddStandaloneItem::class)($done, $this->makeProduct($this->tenant, 'Refri', 8)->id);
        app(AddSalePayment::class)($done, ['payment_option_id' => $this->cash->id, 'amount' => 8]);
        app(FinalizeOpenSale::class)($done);

        Livewire::test(ListSales::class)
            ->assertSee('Aberta')
            ->assertTableActionVisible('resume', $draft)
            ->assertTableActionHidden('resume', $done)
            ->assertTableActionHasUrl('resume', NewSale::getUrl(['sale' => $draft->id]), $draft);
    }

    public function test_a_fresh_screen_shows_no_order_as_launched_and_the_beacon_carries_the_current_draft(): void
    {
        $this->actingAsRole('Caixa');
        $this->order();

        $component = Livewire::test(NewSale::class)
            ->assertSet('saleId', null)
            ->assertDontSeeHtml(' checked')
            ->assertSeeHtml('__SALE__');

        $component->call('launchOrder', Order::query()->latest('id')->value('id'))->assertSeeHtml(' checked');
    }

    public function test_the_orders_list_warns_when_there_are_more_pending_orders_than_it_shows(): void
    {
        $this->actingAsRole('Caixa');

        foreach (range(1, 51) as $i) {
            $this->makeSaleOrder($this->tenant, 10);
        }

        Livewire::test(NewSale::class)->assertSee('Mostrando os 50 pedidos mais recentes');
    }

    public function test_forged_ids_never_touch_other_drafts_other_tenants_or_foreign_payment_options(): void
    {
        $this->actingAsRole('Caixa');
        $mine = app(StartSale::class)();
        $theirs = app(StartSale::class)();
        $foreignItem = app(AddStandaloneItem::class)($theirs, $this->makeProduct($this->tenant, 'Outra venda', 40)->id);
        $foreignPayment = app(AddSalePayment::class)($theirs, ['payment_option_id' => $this->cash->id, 'amount' => 40]);

        $other = $this->makeSaleTenant('emporiodapizza');
        $alienOption = $this->makePaymentOption($other, 'Opção alheia');
        $alienClient = Client::create(['tenant_id' => $other->id, 'name' => 'Alheio', 'phone' => '11977776666']);
        $alienOrder = $this->makeNfceOrder($other, [['product' => $this->makeProduct($other, 'Alheia', 40)]]);
        $alienProduct = $this->makeProduct($other, 'Produto alheio', 10);

        $component = Livewire::test(NewSale::class, ['sale' => $mine->id]);

        $component->call('removeCartLine', 'standalone', $foreignItem->id)->assertNotified();
        $component->call('changeQuantity', $foreignItem->id, 9)->assertNotified();
        $component->call('removePayment', $foreignPayment->id)->assertNotified();
        $component->call('editPayment', $foreignPayment->id)->assertSet('paymentModalOpen', false);
        $component->call('selectClient', $alienClient->id)->assertNotified();
        $component->call('launchOrder', $alienOrder->id)->assertNotified();
        $component->dispatch('product-selected', productId: $alienProduct->id)->assertNotified();
        $component->set('paymentOptionId', $alienOption->id)->set('receivedAmount', 10.0)->call('registerPayment')->assertHasErrors('receivedAmount');
        $component->call('splitBill', 2, $alienOption->id)->assertNotified();

        $this->assertSame(1, SaleItem::where('sale_id', $theirs->id)->count());
        $this->assertSame('40.00', $foreignItem->fresh()->total);
        $this->assertSame(1, SalePayment::where('sale_id', $theirs->id)->count());
        $this->assertNull($mine->fresh()->client_id);
        $this->assertSame(0, SalePayment::where('sale_id', $mine->id)->count());
    }

    public function test_a_second_tab_finalizing_the_same_sale_is_told_and_redirected_not_shown_a_wrong_error(): void
    {
        $this->actingAsRole('Caixa');
        $sale = app(StartSale::class)();
        app(AddStandaloneItem::class)($sale, $this->makeProduct($this->tenant, 'Pizza', 40)->id);
        app(AddSalePayment::class)($sale, ['payment_option_id' => $this->cash->id, 'amount' => 40]);

        $first = Livewire::test(NewSale::class, ['sale' => $sale->id]);
        $second = Livewire::test(NewSale::class, ['sale' => $sale->id]);

        $first->call('finalizeSale')->assertHasNoErrors();
        $second->call('finalizeSale')->assertHasNoErrors()->assertNotified()->assertRedirect(NewSale::getUrl());

        $this->assertSame(1, Sale::count());
    }

    public function test_a_repeated_click_after_finalizing_shows_no_error_and_creates_nothing(): void
    {
        $this->actingAsRole('Caixa');

        Livewire::test(NewSale::class)
            ->dispatch('product-selected', productId: $this->makeProduct($this->tenant, 'Pizza', 40)->id)
            ->set('paymentOptionId', $this->cash->id)->set('receivedAmount', 40.0)->call('registerPayment')
            ->call('finalizeSale')
            ->call('finalizeSale')
            ->assertHasNoErrors();

        $this->assertSame(1, Sale::count());
    }

    public function test_the_finalize_error_goes_away_once_the_payment_is_fixed(): void
    {
        $this->actingAsRole('Caixa');

        Livewire::test(NewSale::class)
            ->dispatch('product-selected', productId: $this->makeProduct($this->tenant, 'Pizza', 40)->id)
            ->call('finalizeSale')
            ->assertHasErrors('finalize')
            ->set('paymentOptionId', $this->cash->id)->set('receivedAmount', 40.0)->call('registerPayment')
            ->assertHasNoErrors();
    }

    public function test_another_tenants_sale_cannot_be_resumed(): void
    {
        $this->actingAsRole('Caixa');
        $other = $this->makeSaleTenant('emporiodapizza');
        $this->actAsTenant($other);
        $foreign = app(StartSale::class)();
        $this->actAsTenant($this->tenant);

        Livewire::test(NewSale::class, ['sale' => $foreign->id])->assertRedirect(NewSale::getUrl());
    }

    public function test_the_locked_sale_id_cannot_be_tampered_with_from_the_browser(): void
    {
        $this->actingAsRole('Caixa');

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(NewSale::class)->set('saleId', 99);
    }

    // ---- sendBeacon -------------------------------------------------------

    public function test_the_beacon_discards_an_empty_draft_and_never_one_with_content(): void
    {
        $this->actingAsRole('Caixa');
        $empty = app(StartSale::class)();
        $full = app(StartSale::class)();
        app(AddStandaloneItem::class)($full, $this->makeProduct($this->tenant, 'Pizza', 40)->id);

        $this->post(route('sales.discard_empty', ['sale' => $empty->id]))->assertNoContent();
        $this->post(route('sales.discard_empty', ['sale' => $full->id]))->assertNoContent();

        $this->assertNull(Sale::find($empty->id));
        $this->assertNotNull(Sale::find($full->id));
    }

    public function test_the_beacon_is_refused_without_permission_and_ignores_other_tenants(): void
    {
        $other = $this->makeSaleTenant('emporiodapizza');
        $this->actAsTenant($other);
        $foreign = app(StartSale::class)();
        $this->actAsTenant($this->tenant);
        $own = app(StartSale::class)();

        $this->actingAsRole('Atendente');
        $this->post(route('sales.discard_empty', ['sale' => $own->id]))->assertForbidden();
        $this->assertNotNull(Sale::find($own->id));

        $this->actingAsRole('Caixa');
        $this->post(route('sales.discard_empty', ['sale' => $foreign->id]))->assertNoContent();
        $this->assertNotNull(Sale::withoutGlobalScopes()->find($foreign->id), 'Rascunho de outro estabelecimento não é tocado.');
    }

    public function test_the_beacon_requires_a_logged_in_user(): void
    {
        $sale = app(StartSale::class)();

        $this->post(route('sales.discard_empty', ['sale' => $sale->id]))->assertForbidden();
        $this->assertNotNull(Sale::find($sale->id));
    }
}
