<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Models\Client;
use App\Models\DeliveryOption;
use App\Models\Order;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * RF-28: confirmação de entrega sem login, via link assinado. A mesma rota
 * GET+POST protegida por middleware "signed" — o form de confirmação
 * reenvia a própria URL assinada (ver DeliveryConfirmationController).
 */
class DeliveryConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);

        $client = Client::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cliente Teste',
            'phone' => '11999990000',
        ]);

        $deliveryOption = DeliveryOption::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Entrega padrão',
            'delivery_fee' => 5,
        ]);

        $this->order = Order::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'delivery_option_id' => $deliveryOption->id,
            'items_total' => 40,
            'grand_total' => 45,
            'status' => OrderStatus::InTransit,
            'opened_at' => now(),
        ]);
    }

    private function signedUrl(): string
    {
        return URL::temporarySignedRoute('delivery.confirmation', now()->addHours(12), ['order' => $this->order->id]);
    }

    public function test_get_with_valid_signature_shows_confirmation_form(): void
    {
        $this->get($this->signedUrl())
            ->assertOk()
            ->assertSee('Confirmar entrega');

        $this->assertSame(OrderStatus::InTransit, $this->order->fresh()->status);
    }

    public function test_post_with_valid_signature_marks_order_as_delivered(): void
    {
        $this->post($this->signedUrl())
            ->assertOk()
            ->assertSee('Entrega confirmada');

        $order = $this->order->fresh();
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertNotNull($order->delivered_at);
    }

    public function test_reopening_link_after_delivery_shows_already_delivered_state(): void
    {
        $this->post($this->signedUrl());

        $this->get($this->signedUrl())
            ->assertOk()
            ->assertSee('Entrega confirmada');
    }

    public function test_get_with_tampered_signature_is_rejected(): void
    {
        $tampered = $this->signedUrl().'0';

        $this->get($tampered)->assertForbidden();
    }

    public function test_get_with_expired_signature_is_rejected(): void
    {
        $expired = URL::temporarySignedRoute('delivery.confirmation', now()->subHour(), ['order' => $this->order->id]);

        $this->get($expired)->assertForbidden();
    }

    public function test_order_from_another_tenant_is_not_reachable_even_with_valid_signature_shape(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Outro Tenant',
            'slug' => 'outro-tenant',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511988888888',
        ]);

        CurrentTenant::set($otherTenant);
        $otherClient = Client::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Cliente Outro Tenant',
            'phone' => '11988887777',
        ]);
        $otherOrder = Order::create([
            'tenant_id' => $otherTenant->id,
            'client_id' => $otherClient->id,
            'items_total' => 10,
            'grand_total' => 10,
            'status' => OrderStatus::InTransit,
            'opened_at' => now(),
        ]);

        // Link assinado válido para o domínio do tenant CORRETO, mas apontando pro id
        // de um pedido que pertence a outro tenant — TenantScope deve isolar (404).
        URL::defaults(['tenant' => $this->tenant->slug]);
        $url = URL::temporarySignedRoute('delivery.confirmation', now()->addHours(12), ['order' => $otherOrder->id]);

        $this->get($url)->assertNotFound();
    }
}
