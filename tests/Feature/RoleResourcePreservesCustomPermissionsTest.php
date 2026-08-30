<?php

namespace Tests\Feature;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\Roles\Pages\EditRole;
use App\Filament\Tenant\Resources\Roles\RoleResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Regressão: editar e salvar a role Admin em Filament > Roles não pode
 * apagar permissões que não mapeiam pra Resource/Page/Widget do Shield
 * (manage_order_status, mark_order_delivered, edit_order_advanced_status).
 * Ver .ai/rules/config.md.
 */
class RoleResourcePreservesCustomPermissionsTest extends TestCase
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
            'plan_id' => $this->planWithAllFeatures()->id,
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(SeedDefaultTenantRoles::class)($this->tenant);

        // RoleResource não é semeado por SeedDefaultTenantRoles (só Order/Kitchen/
        // OrderSettings/ProductionLine) — sem isso o Admin não teria Update:Role
        // pra sequer abrir a página de edição de role neste teste.
        $roleResourcePermissions = collect(FilamentShield::getResources()[RoleResource::class]['permissions'] ?? [])
            ->pluck('key')
            ->filter()
            ->map(fn (string $name) => Permission::findOrCreate($name));
        Role::findByName('Admin')->givePermissionTo($roleResourcePermissions);

        $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

    public function test_resaving_admin_role_preserves_custom_permissions(): void
    {
        $adminRole = Role::findByName('Admin');

        $this->assertTrue($adminRole->hasPermissionTo('manage_order_status'));
        $this->assertTrue($adminRole->hasPermissionTo('mark_order_delivered'));
        $this->assertTrue($adminRole->hasPermissionTo('edit_order_advanced_status'));

        Livewire::test(EditRole::class, ['record' => $adminRole->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $adminRole->refresh();

        $this->assertTrue($adminRole->hasPermissionTo('manage_order_status'));
        $this->assertTrue($adminRole->hasPermissionTo('mark_order_delivered'));
        $this->assertTrue($adminRole->hasPermissionTo('edit_order_advanced_status'));
    }
}
