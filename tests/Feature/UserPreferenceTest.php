<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

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

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_value_for_returns_default_when_nothing_is_saved(): void
    {
        $this->assertSame(['foo' => 'bar'], UserPreference::valueFor($this->user, 'unknown.key', ['foo' => 'bar']));
    }

    public function test_remember_for_creates_a_new_preference(): void
    {
        UserPreference::rememberFor($this->user, 'kitchen.filters', ['quickFilter' => 'delivery']);

        $this->assertSame(['quickFilter' => 'delivery'], UserPreference::valueFor($this->user, 'kitchen.filters'));
        $this->assertDatabaseHas('user_preferences', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'key' => 'kitchen.filters',
        ]);
    }

    public function test_remember_for_updates_an_existing_preference_instead_of_duplicating(): void
    {
        UserPreference::rememberFor($this->user, 'kitchen.filters', ['quickFilter' => 'delivery']);
        UserPreference::rememberFor($this->user, 'kitchen.filters', ['quickFilter' => 'pickup']);

        $this->assertSame(['quickFilter' => 'pickup'], UserPreference::valueFor($this->user, 'kitchen.filters'));
        $this->assertSame(1, UserPreference::where('user_id', $this->user->id)->where('key', 'kitchen.filters')->count());
    }

    public function test_preferences_are_scoped_by_tenant(): void
    {
        UserPreference::rememberFor($this->user, 'kitchen.filters', ['quickFilter' => 'delivery']);

        $otherTenant = Tenant::create([
            'name' => 'Outro Tenant',
            'slug' => 'outro-tenant',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511988888888',
        ]);

        CurrentTenant::set($otherTenant);

        $this->assertSame(0, UserPreference::query()->count());
    }
}
