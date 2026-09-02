<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\CreateOrderFromCart;
use App\Actions\Orders\UpdateOrderFromCart;
use App\Enums\OrderOrigin;
use App\Exceptions\CheckoutException;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\ProductGift;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Brinde no pedido (RN-53): aceito move estoque físico do produto-brinde pelo
 * fluxo centralizado, mas não conta como venda (sales_count) e não altera o
 * total. Recusado é registrado sem movimentar nada. Sem estoque, bloqueia.
 */
class OrderGiftTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private PaymentOption $paymentOption;

    private Product $product;

    private Product $gift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Pizzaria Teste',
            'slug' => 'pizzaria-teste',
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);

        $this->paymentOption = PaymentOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Dinheiro', 'is_cash' => true]);

        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $this->product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Pizza Calabresa', 'price' => 65]);
        $this->gift = Product::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Guaraná 1,5L',
            'price' => 12, 'controls_stock' => true, 'stock_quantity' => 10,
        ]);

        ProductGift::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'gift_product_id' => $this->gift->id, 'quantity' => 1, 'is_active' => true]);
    }

    /**
     * @param  array<int, array{gift_product_id:int, accepted:bool}>  $gifts
     */
    private function placeOrder(array $gifts, int $quantity = 1, float $amount = 65.0): Order
    {
        return app(CreateOrderFromCart::class)(
            [[
                'type' => 'simple', 'product_id' => $this->product->id, 'flavor_ids' => [], 'quantity' => $quantity, 'note' => null,
                'addons' => [], 'gifts' => $gifts,
            ]],
            [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $this->paymentOption->id, 'amount' => $amount, 'change_for' => null]],
            ],
            origin: OrderOrigin::Staff,
            bypassBusinessHours: true,
        );
    }

    public function test_accepted_gift_moves_stock_but_not_sales_count_and_keeps_total(): void
    {
        $order = $this->placeOrder([['gift_product_id' => $this->gift->id, 'accepted' => true]]);

        $this->assertSame('9.00', $this->gift->fresh()->stock_quantity);
        $this->assertSame('0.00', $this->gift->fresh()->sales_count);
        $this->assertSame('65.00', (string) $order->items_total);
        $this->assertSame('65.00', (string) $order->grand_total);
        $this->assertSame('0.00', (string) $order->discount_total);

        $this->assertEquals([
            ['gift_product_id' => $this->gift->id, 'quantity' => 1, 'accepted' => true],
        ], $order->items->first()->gifts);
    }

    public function test_gift_units_scale_with_parent_line_quantity(): void
    {
        $this->placeOrder([['gift_product_id' => $this->gift->id, 'accepted' => true]], quantity: 3, amount: 195.0);

        $this->assertSame('7.00', $this->gift->fresh()->stock_quantity); // 10 - (3 * 1)
    }

    public function test_declined_gift_moves_no_stock_but_is_recorded(): void
    {
        $order = $this->placeOrder([]);

        $this->assertSame('10.00', $this->gift->fresh()->stock_quantity);
        $this->assertEquals([
            ['gift_product_id' => $this->gift->id, 'quantity' => 1, 'accepted' => false],
        ], $order->items->first()->gifts);
    }

    public function test_accepted_gift_without_stock_blocks_the_order(): void
    {
        $this->gift->update(['stock_quantity' => 0]);

        $this->expectException(CheckoutException::class);

        $this->placeOrder([['gift_product_id' => $this->gift->id, 'accepted' => true]]);
    }

    public function test_editing_order_reverts_the_old_gift_stock(): void
    {
        $order = $this->placeOrder([['gift_product_id' => $this->gift->id, 'accepted' => true]]);
        $this->assertSame('9.00', $this->gift->fresh()->stock_quantity);

        app(UpdateOrderFromCart::class)(
            $order,
            [[
                'type' => 'simple', 'product_id' => $this->product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
                'addons' => [], 'gifts' => [],
            ]],
            [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $this->paymentOption->id, 'amount' => 65.0, 'change_for' => null]],
            ],
        );

        $this->assertSame('10.00', $this->gift->fresh()->stock_quantity);
        $this->assertEquals([
            ['gift_product_id' => $this->gift->id, 'quantity' => 1, 'accepted' => false],
        ], $order->fresh()->items->first()->gifts);
    }

    public function test_persisted_gift_quantity_is_a_snapshot_not_affected_by_later_link_changes(): void
    {
        $order = $this->placeOrder([['gift_product_id' => $this->gift->id, 'accepted' => true]]);

        ProductGift::where('product_id', $this->product->id)->update(['quantity' => 5]);

        $this->assertSame(1, $order->fresh()->items->first()->gifts[0]['quantity']);
    }
}
