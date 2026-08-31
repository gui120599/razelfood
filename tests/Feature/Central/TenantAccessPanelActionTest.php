<?php

namespace Tests\Feature\Central;

use App\Enums\CentralRole;
use App\Enums\TenantStatus;
use App\Filament\Resources\Tenants\Pages\ListTenants;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TenantAccessPanelActionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('central'));

        $this->tenant = Tenant::create([
            'name' => 'Empório da Pizza',
            'slug' => 'emporio',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);
    }

    public function test_platform_admin_sees_the_action_pointing_to_the_tenant_panel(): void
    {
        $this->actingAsPlatformAdmin();

        Livewire::test(ListTenants::class)
            ->assertTableActionVisible('accessPanel', $this->tenant)
            ->assertTableActionHasUrl(
                'accessPanel',
                Filament::getPanel('tenant')->getUrl($this->tenant),
                $this->tenant,
            );
    }

    public function test_support_role_does_not_see_the_action(): void
    {
        $this->actingAs(User::factory()->create([
            'tenant_id' => null,
            'central_role' => CentralRole::Support,
        ]));

        Livewire::test(ListTenants::class)
            ->assertTableActionHidden('accessPanel', $this->tenant);
    }
}
