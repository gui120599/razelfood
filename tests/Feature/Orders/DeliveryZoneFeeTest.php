<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\ResolveDeliveryFee;
use App\Enums\TenantStatus;
use App\Exceptions\CheckoutException;
use App\Models\DeliveryOption;
use App\Models\DeliveryZone;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RN-34 a RN-38: taxa de entrega resolvida pelo bairro do cliente, com
 * fallback para a taxa fixa da DeliveryOption enquanto o tenant não tiver
 * nenhum setor cadastrado.
 */
class DeliveryZoneFeeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private DeliveryOption $deliveryOption;

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

        $this->deliveryOption = DeliveryOption::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Entrega padrão',
            'delivery_fee' => 8,
        ]);
    }

    public function test_matches_configured_zone_and_applies_its_fee(): void
    {
        $zone = DeliveryZone::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Centro',
            'delivery_fee' => 5.50,
        ]);
        $zone->neighborhoods()->create(['neighborhood' => 'Centro', 'city' => 'Testópolis']);

        $result = app(ResolveDeliveryFee::class)($this->deliveryOption, 'Centro', 'Testópolis', 0);

        $this->assertSame(5.5, $result['fee']);
        $this->assertSame($zone->id, $result['delivery_zone_id']);
        $this->assertFalse($result['is_unlisted_neighborhood']);
    }

    public function test_matches_neighborhood_ignoring_case_and_accents(): void
    {
        $zone = DeliveryZone::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Centro',
            'delivery_fee' => 5.50,
        ]);
        $zone->neighborhoods()->create(['neighborhood' => 'São João', 'city' => 'São Paulo']);

        $result = app(ResolveDeliveryFee::class)($this->deliveryOption, 'sao joao', 'sao paulo', 0);

        $this->assertSame($zone->id, $result['delivery_zone_id']);
    }

    public function test_blocks_when_neighborhood_not_mapped_and_tenant_does_not_serve_unlisted(): void
    {
        DeliveryZone::create(['tenant_id' => $this->tenant->id, 'name' => 'Centro', 'delivery_fee' => 5]);
        $this->tenant->update(['serves_unlisted_neighborhoods' => false]);

        $this->expectException(CheckoutException::class);

        app(ResolveDeliveryFee::class)($this->deliveryOption, 'Bairro Fantasma', null, 0);
    }

    /**
     * RN-37 caso 2 (20/08/2026): a taxa de bairro não configurado é SOMADA
     * à taxa normal da opção de entrega, não a substitui — o cliente vê os
     * dois valores no checkout (RF-39).
     */
    public function test_sums_base_delivery_fee_and_unlisted_surcharge_when_neighborhood_not_mapped(): void
    {
        DeliveryZone::create(['tenant_id' => $this->tenant->id, 'name' => 'Centro', 'delivery_fee' => 5]);
        $this->tenant->update(['serves_unlisted_neighborhoods' => true, 'unlisted_neighborhood_fee' => 15]);

        $result = app(ResolveDeliveryFee::class)($this->deliveryOption, 'Bairro Fantasma', null, 0);

        $this->assertSame(8.0, $result['base_fee']); // taxa normal da opção "Entrega padrão"
        $this->assertSame(15.0, $result['unlisted_surcharge']);
        $this->assertSame(23.0, $result['fee']); // 8 + 15
        $this->assertNull($result['delivery_zone_id']);
        $this->assertTrue($result['is_unlisted_neighborhood']);
    }

    /**
     * Quando o pedido atinge o mínimo pra isenção, a taxa NORMAL zera —
     * mas a taxa de bairro não configurado continua valendo sozinha
     * (RN-37 caso 2 + RN-38).
     */
    public function test_free_delivery_minimum_zeroes_only_the_base_fee_not_the_unlisted_surcharge(): void
    {
        $this->deliveryOption->update(['min_order_for_free_delivery' => 50]);
        DeliveryZone::create(['tenant_id' => $this->tenant->id, 'name' => 'Centro', 'delivery_fee' => 5]);
        $this->tenant->update(['serves_unlisted_neighborhoods' => true, 'unlisted_neighborhood_fee' => 15]);

        $result = app(ResolveDeliveryFee::class)($this->deliveryOption, 'Bairro Fantasma', null, 60);

        $this->assertSame(0.0, $result['base_fee']);
        $this->assertSame(15.0, $result['unlisted_surcharge']);
        $this->assertSame(15.0, $result['fee']);
    }

    public function test_falls_back_to_flat_delivery_option_fee_when_tenant_has_no_zones_configured(): void
    {
        $this->tenant->update(['serves_unlisted_neighborhoods' => false]);

        $result = app(ResolveDeliveryFee::class)($this->deliveryOption, 'Qualquer Bairro', null, 0);

        $this->assertSame(8.0, $result['fee']);
        $this->assertNull($result['delivery_zone_id']);
        $this->assertFalse($result['is_unlisted_neighborhood']);
    }

    public function test_applies_free_delivery_threshold_from_delivery_option(): void
    {
        $this->deliveryOption->update(['min_order_for_free_delivery' => 50]);

        $zone = DeliveryZone::create(['tenant_id' => $this->tenant->id, 'name' => 'Centro', 'delivery_fee' => 5.50]);
        $zone->neighborhoods()->create(['neighborhood' => 'Centro']);

        $result = app(ResolveDeliveryFee::class)($this->deliveryOption, 'Centro', null, 60);

        $this->assertSame(0.0, $result['fee']);
    }
}
