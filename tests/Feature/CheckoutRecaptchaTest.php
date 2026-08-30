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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RN-29: verificação anti-robô (reCAPTCHA) no checkout, configurável por
 * tenant. O token vem do cliente mas o servidor sempre revalida
 * (App\Services\Security\RecaptchaVerifier).
 */
class CheckoutRecaptchaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private PaymentOption $cash;

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

    private function enableRecaptcha(): void
    {
        $this->tenant->update([
            'recaptcha_enabled' => true,
            'recaptcha_site_key' => 'site-key',
            'recaptcha_secret_key' => 'secret-key',
        ]);
        CurrentTenant::set($this->tenant->fresh());
    }

    public function test_checkout_works_normally_when_recaptcha_is_disabled(): void
    {
        Http::fake();

        Livewire::test(Checkout::class)
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->call('selectPaymentOptionForLine', 0, $this->cash->id)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(1, Order::count());
        Http::assertNothingSent();
    }

    public function test_checkout_is_blocked_when_recaptcha_verification_fails(): void
    {
        $this->enableRecaptcha();
        Http::fake([
            'www.google.com/recaptcha/api/siteverify' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]),
        ]);

        Livewire::test(Checkout::class)
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('recaptchaToken', 'client-token')
            ->call('selectPaymentOptionForLine', 0, $this->cash->id)
            ->call('submit')
            ->assertSee('não é um robô');

        $this->assertSame(0, Order::count());
    }

    public function test_checkout_proceeds_when_recaptcha_verification_succeeds(): void
    {
        $this->enableRecaptcha();
        Http::fake([
            'www.google.com/recaptcha/api/siteverify' => Http::response(['success' => true, 'score' => 0.9]),
        ]);

        Livewire::test(Checkout::class)
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('recaptchaToken', 'client-token')
            ->call('selectPaymentOptionForLine', 0, $this->cash->id)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(1, Order::count());
    }

    public function test_checkout_is_blocked_when_token_is_missing_and_recaptcha_is_enabled(): void
    {
        $this->enableRecaptcha();
        Http::fake();

        Livewire::test(Checkout::class)
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->call('selectPaymentOptionForLine', 0, $this->cash->id)
            ->call('submit')
            ->assertSee('não é um robô');

        $this->assertSame(0, Order::count());
    }

    public function test_checkout_proceeds_when_google_is_unreachable(): void
    {
        $this->enableRecaptcha();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        Livewire::test(Checkout::class)
            ->set('phone', '11999990000')
            ->set('name', 'Cliente Teste')
            ->set('recaptchaToken', 'client-token')
            ->call('selectPaymentOptionForLine', 0, $this->cash->id)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(1, Order::count());
    }
}
