<?php

namespace Tests\Feature;

use App\Http\Middleware\IdentifyTenant;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Regressão: `config('tenancy.cache.ttl_minutes')` vindo do .env é string
 * ('5'), e o Carbon 3 (via Symfony 8) rejeita string em now()->addMinutes(),
 * derrubando toda requisição de subdomínio de tenant com HTTP 500. O cast
 * mora tanto em config/tenancy.php quanto no próprio middleware.
 */
class IdentifyTenantCacheTtlTest extends TestCase
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

        $baseDomain = config('tenancy.base_domain');
        $request = Request::create("http://{$tenant->slug}.{$baseDomain}/");

        $response = app(IdentifyTenant::class)->handle($request, fn () => response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    public function test_config_casts_ttl_minutes_to_int(): void
    {
        $this->assertIsInt(config('tenancy.cache.ttl_minutes'));
    }
}
