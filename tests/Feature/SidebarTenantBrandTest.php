<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Filament\Tenant\Pages\EstablishmentSettings;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * O brand do tenant no topo da navegação (SIDEBAR_NAV_START) leva para
 * as Configurações do Estabelecimento quando o usuário tem acesso, e
 * usa cores que respeitam o tema claro/escuro.
 */
class SidebarTenantBrandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $feature = Feature::create(['key' => FeatureKey::CONFIGURACOES_ESTABELECIMENTO, 'name' => 'Configurações do Estabelecimento', 'is_available' => true]);
        $plan = Plan::create(['name' => 'Completo', 'slug' => 'completo']);
        $plan->features()->attach($feature);

        $this->tenant = Tenant::create([
            'name' => 'Empório da Pizza',
            'slug' => 'emporio',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
            'watermark_height' => 288,
            'plan_id' => $plan->id,
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));

        $this->actingAs(User::factory()->create(['tenant_id' => $this->tenant->id]));
    }

    private function render(): string
    {
        return view('filament.tenant.partials.sidebar-tenant-brand')->render();
    }

    public function test_brand_links_to_establishment_settings_when_user_has_access(): void
    {
        Gate::before(fn () => true);

        $html = $this->render();

        $this->assertStringContainsString('<a', $html);
        $this->assertStringContainsString('href="'.EstablishmentSettings::getUrl(panel: 'tenant').'"', $html);
        $this->assertStringContainsString('Empório da Pizza', $html);
    }

    public function test_brand_is_not_a_link_without_permission(): void
    {
        Gate::before(fn () => false);

        $html = $this->render();

        $this->assertStringNotContainsString('<a', $html);
        $this->assertStringContainsString('Empório da Pizza', $html);
    }

    public function test_brand_uses_theme_aware_text_colors(): void
    {
        Gate::before(fn () => true);

        $html = $this->render();

        $this->assertStringContainsString('text-gray-700', $html);
        $this->assertStringContainsString('dark:text-white', $html);
        $this->assertStringNotContainsString('border-white/10 bg-white/5', $html);
    }
}
