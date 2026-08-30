<?php

namespace Tests\Feature\Support;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\CurrentTenant;
use App\Support\Preferences\PersistsFilterPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testa a trait diretamente (sem depender de Livewire::test/#[Url], que não
 * dá pra simular de forma confiável em teste) — cobre exatamente a garantia
 * que importa pro Kitchen: `skipIfAlreadySet` não deixa a preferência salva
 * sobrescrever um valor que já veio de outra fonte com prioridade maior
 * (ex.: querystring).
 */
class PersistsFilterPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($tenant);

        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($this->user);
    }

    private function makeComponent(?string $initialFoo): object
    {
        return new class($initialFoo)
        {
            use PersistsFilterPreferences;

            public ?string $foo;

            public function __construct(?string $foo)
            {
                $this->foo = $foo;
            }

            public function load(string $key, array $properties, array $skipIfAlreadySet = []): void
            {
                $this->loadFilterPreferences($key, $properties, $skipIfAlreadySet);
            }

            public function persist(string $key, array $properties): void
            {
                $this->persistFilterPreferences($key, $properties);
            }
        };
    }

    public function test_loading_fills_the_property_from_the_saved_preference(): void
    {
        UserPreference::rememberFor($this->user, 'test.key', ['foo' => 'saved-value']);

        $component = $this->makeComponent(null);
        $component->load('test.key', ['foo']);

        $this->assertSame('saved-value', $component->foo);
    }

    public function test_skip_if_already_set_keeps_a_non_null_value_from_being_overwritten(): void
    {
        UserPreference::rememberFor($this->user, 'test.key', ['foo' => 'saved-value']);

        $component = $this->makeComponent('came-from-elsewhere');
        $component->load('test.key', ['foo'], skipIfAlreadySet: ['foo']);

        $this->assertSame('came-from-elsewhere', $component->foo);
    }

    public function test_skip_if_already_set_still_applies_the_saved_value_when_the_property_is_null(): void
    {
        UserPreference::rememberFor($this->user, 'test.key', ['foo' => 'saved-value']);

        $component = $this->makeComponent(null);
        $component->load('test.key', ['foo'], skipIfAlreadySet: ['foo']);

        $this->assertSame('saved-value', $component->foo);
    }

    public function test_persisting_saves_the_current_property_values(): void
    {
        $component = $this->makeComponent('current-value');
        $component->persist('test.key', ['foo']);

        $this->assertSame(['foo' => 'current-value'], UserPreference::valueFor($this->user, 'test.key'));
    }
}
