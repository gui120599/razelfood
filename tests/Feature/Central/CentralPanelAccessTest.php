<?php

namespace Tests\Feature\Central;

use App\Enums\CentralRole;
use App\Filament\Resources\Plans\PlanResource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RF-40, RF-41, RN-44: gestão de planos/features é restrita à equipe
 * interna da Razel Tec (tenant_id nulo) — um usuário de tenant nunca deve
 * alcançar o painel central, mesmo sabendo a URL. Entre os usuários
 * internos, o `central_role` decide o que cada um enxerga.
 */
class CentralPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_user_cannot_access_the_central_panel(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste', 'whatsapp_number' => '5511999999999',
        ]);
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));

        $this->get(PlanResource::getUrl('index'))->assertForbidden();
    }

    public function test_platform_admin_can_access_the_central_panel(): void
    {
        $this->actingAsPlatformAdmin();

        $this->get(PlanResource::getUrl('index'))->assertOk();
    }

    public function test_central_user_without_a_role_sees_no_resources(): void
    {
        $this->actingAs(User::factory()->create(['tenant_id' => null, 'central_role' => null]));

        $this->get(PlanResource::getUrl('index'))->assertForbidden();
    }

    public function test_support_user_cannot_reach_plans(): void
    {
        $this->actingAs(User::factory()->create(['tenant_id' => null, 'central_role' => CentralRole::Support]));

        $this->get(PlanResource::getUrl('index'))->assertForbidden();
    }
}
