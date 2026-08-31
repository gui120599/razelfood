<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressão: `config('tenancy.cache.ttl_minutes')` vindo do .env é string
 * ('5'), e o Carbon 3 (via Symfony 8) rejeita string em now()->addMinutes(),
 * derrubando toda requisição de cardápio de tenant com HTTP 500. O cast mora
 * tanto em config/tenancy.php quanto no próprio ResolveTenantFromPath.
 */
class ResolveTenantFromPathCacheTtlTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_tenant_when_cache_ttl_is_a_string(): void
    {
        config([
            'tenancy.cache.enabled' => true,
            'tenancy.cache.ttl_minutes' => '5',
        ]);

        $tenant = Tenant::create([
            'name' => 'Lazzo Pizza',
            'slug' => 'lazzo',
            'whatsapp_number' => '5511999999999',
        ]);

        $this->get("/{$tenant->slug}")->assertOk();
    }

    public function test_config_casts_ttl_minutes_to_int(): void
    {
        $this->assertIsInt(config('tenancy.cache.ttl_minutes'));
    }
}
