<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * O cardápio público (x-layouts.public) usa o favicon do tenant quando
 * configurado (tenants.favicon_path) e cai no ícone RazelFood caso contrário.
 */
class PublicMenuFaviconTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(array $attributes = []): Tenant
    {
        $tenant = Tenant::create(array_merge([
            'name' => 'Pizzaria Teste',
            'slug' => 'pizzaria-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ], $attributes));

        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);

        return $tenant;
    }

    public function test_menu_uses_the_tenant_favicon_when_configured(): void
    {
        Storage::fake('public');
        $this->tenant(['favicon_path' => 'tenants/favicons/meu-favicon.png']);

        $expectedUrl = Storage::disk('public')->url('tenants/favicons/meu-favicon.png');

        $this->get(route('menu.index'))
            ->assertOk()
            ->assertSee('<link rel="icon" type="image/png" href="'.e($expectedUrl).'">', false);
    }

    public function test_menu_falls_back_to_the_razelfood_icon_without_a_favicon(): void
    {
        $this->tenant();

        $this->get(route('menu.index'))
            ->assertOk()
            ->assertSee('images/brand/razelfood-icon-32.png', false);
    }
}
