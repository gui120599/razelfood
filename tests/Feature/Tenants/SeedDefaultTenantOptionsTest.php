<?php

namespace Tests\Feature\Tenants;

use App\Actions\Tenants\SeedDefaultTenantOptions;
use App\Enums\TenantStatus;
use App\Models\DeliveryOption;
use App\Models\PaymentOption;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedDefaultTenantOptionsTest extends TestCase
{
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
        ]);

        CurrentTenant::set($this->tenant);
    }

    public function test_creates_the_default_delivery_and_payment_options(): void
    {
        app(SeedDefaultTenantOptions::class)($this->tenant);

        $delivery = DeliveryOption::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->get()->keyBy('name');
        $this->assertCount(3, $delivery);
        $this->assertFalse((bool) $delivery['Retirada']->requires_address);
        $this->assertTrue((bool) $delivery['Entregar']->requires_address);
        $this->assertFalse((bool) $delivery['Comer no local']->requires_address);

        $payment = PaymentOption::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->get()->keyBy('name');
        $this->assertCount(4, $payment);
        $this->assertTrue((bool) $payment['Dinheiro']->is_cash);
        $this->assertFalse((bool) $payment['Cartão Débito']->is_cash);
        $this->assertFalse((bool) $payment['Cartão Crédito']->is_cash);
        $this->assertFalse((bool) $payment['Pix']->is_cash);
    }

    public function test_running_twice_does_not_duplicate_records(): void
    {
        app(SeedDefaultTenantOptions::class)($this->tenant);
        app(SeedDefaultTenantOptions::class)($this->tenant);

        $this->assertSame(3, DeliveryOption::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(4, PaymentOption::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_does_not_affect_another_tenants_options(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Outro Tenant',
            'slug' => 'outro-tenant',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511988888888',
        ]);

        app(SeedDefaultTenantOptions::class)($this->tenant);

        $this->assertSame(0, DeliveryOption::withoutGlobalScopes()->where('tenant_id', $otherTenant->id)->count());
        $this->assertSame(0, PaymentOption::withoutGlobalScopes()->where('tenant_id', $otherTenant->id)->count());
    }

    public function test_preserves_options_already_customized_by_the_tenant(): void
    {
        app(SeedDefaultTenantOptions::class)($this->tenant);

        $retirada = DeliveryOption::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('name', 'Retirada')->firstOrFail();
        $retirada->update(['delivery_fee' => 12.50]);

        app(SeedDefaultTenantOptions::class)($this->tenant);

        $this->assertSame('12.50', $retirada->fresh()->delivery_fee);
        $this->assertSame(3, DeliveryOption::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_command_seeds_options_when_tenant_resolved_by_slug(): void
    {
        $this->artisan('tenant:seed-options', ['tenant' => $this->tenant->slug])
            ->assertSuccessful();

        $this->assertSame(3, DeliveryOption::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_command_seeds_options_when_tenant_resolved_by_id(): void
    {
        $this->artisan('tenant:seed-options', ['tenant' => (string) $this->tenant->id])
            ->assertSuccessful();

        $this->assertSame(4, PaymentOption::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_command_fails_gracefully_for_an_unknown_tenant(): void
    {
        $this->artisan('tenant:seed-options', ['tenant' => 'nao-existe'])
            ->assertFailed();
    }
}
