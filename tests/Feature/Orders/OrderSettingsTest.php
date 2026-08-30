<?php

namespace Tests\Feature\Orders;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Pages\OrderSettings;
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
 * RN-36/RF-37: o tenant define, na própria tela de configurações, se atende
 * bairros não cadastrados em nenhum setor e a taxa específica desses casos.
 */
class OrderSettingsTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
            'order_attention_after_minutes' => 10,
            'order_late_after_minutes' => 20,
            'plan_id' => $this->planWithAllFeatures()->id,
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(SeedDefaultTenantRoles::class)($this->tenant);

        $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

    public function test_enabling_unlisted_neighborhoods_requires_a_fee(): void
    {
        Livewire::test(OrderSettings::class)
            ->set('data.serves_unlisted_neighborhoods', true)
            ->set('data.unlisted_neighborhood_fee', null)
            ->call('save')
            ->assertHasErrors(['data.unlisted_neighborhood_fee' => 'required']);
    }

    public function test_saving_persists_the_unlisted_neighborhood_settings(): void
    {
        Livewire::test(OrderSettings::class)
            ->set('data.serves_unlisted_neighborhoods', true)
            ->set('data.unlisted_neighborhood_fee', 15)
            ->call('save')
            ->assertHasNoErrors();

        $tenant = $this->tenant->fresh();
        $this->assertTrue($tenant->serves_unlisted_neighborhoods);
        $this->assertSame('15.00', $tenant->unlisted_neighborhood_fee);
    }

    public function test_saving_a_br_formatted_masked_fee_persists_a_plain_decimal(): void
    {
        Livewire::test(OrderSettings::class)
            ->set('data.serves_unlisted_neighborhoods', true)
            ->set('data.unlisted_neighborhood_fee', '1.234,56')
            ->assertSet('data.unlisted_neighborhood_fee', '1234.56')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1234.56', $this->tenant->fresh()->unlisted_neighborhood_fee);
    }

    public function test_disabling_unlisted_neighborhoods_does_not_require_a_fee(): void
    {
        Livewire::test(OrderSettings::class)
            ->set('data.serves_unlisted_neighborhoods', false)
            ->set('data.unlisted_neighborhood_fee', null)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($this->tenant->fresh()->serves_unlisted_neighborhoods);
    }

    public function test_saving_persists_the_delivery_flow_settings(): void
    {
        Livewire::test(OrderSettings::class)
            ->set('data.uses_in_transit_stage', true)
            ->set('data.assigns_delivery_couriers', false)
            ->call('save')
            ->assertHasNoErrors();

        $tenant = $this->tenant->fresh();
        $this->assertTrue($tenant->uses_in_transit_stage);
        $this->assertFalse($tenant->assigns_delivery_couriers);
    }

    public function test_disabling_the_transit_stage_persists_and_the_courier_flag_follows(): void
    {
        Livewire::test(OrderSettings::class)
            ->set('data.uses_in_transit_stage', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($this->tenant->fresh()->uses_in_transit_stage);
    }
}
