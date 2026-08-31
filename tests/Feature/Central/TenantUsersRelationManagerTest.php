<?php

namespace Tests\Feature\Central;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\CentralRole;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Filament\Resources\Tenants\RelationManagers\UsersRelationManager;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * RN-44: o gestor da plataforma também gerencia os usuários de qualquer
 * tenant pelo painel central (onboarding, destravar tenant sem Admin). Os
 * papéis são do spatie/permission com "teams" — precisam cair no team
 * (tenant_id) certo mesmo sem tenant middleware (o painel central não tem).
 */
class TenantUsersRelationManagerTest extends TestCase
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
            'whatsapp_number' => '5511999999999',
        ]);

        app(SeedDefaultTenantRoles::class)($this->tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->actingAs(User::factory()->create(['tenant_id' => null, 'central_role' => CentralRole::Platform]));
    }

    private function manager(): Testable
    {
        return Livewire::test(UsersRelationManager::class, [
            'ownerRecord' => $this->tenant,
            'pageClass' => EditTenant::class,
        ]);
    }

    private function tenantRole(string $name): Role
    {
        return Role::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    public function test_platform_admin_creates_a_tenant_user_with_a_tenant_scoped_role(): void
    {
        $gerente = $this->tenantRole('Gerente');

        $this->manager()
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'name' => 'Maria Gerente',
                'email' => 'maria@emporio.com.br',
                'password' => 'segredo123',
                'roleIds' => [$gerente->id],
            ])
            ->assertHasNoActionErrors();

        $user = User::query()->where('email', 'maria@emporio.com.br')->firstOrFail();

        $this->assertSame($this->tenant->id, $user->tenant_id);
        $this->assertTrue(Hash::check('segredo123', $user->password));
        $this->assertDatabaseHas('model_has_roles', [
            'model_id' => $user->id,
            'role_id' => $gerente->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_editing_replaces_the_tenant_roles(): void
    {
        $gerente = $this->tenantRole('Gerente');
        $atendente = $this->tenantRole('Atendente');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($gerente);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->manager()
            ->callAction(
                TestAction::make('edit')->table($user),
                ['roleIds' => [$atendente->id]],
            )
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('model_has_roles', ['model_id' => $user->id, 'role_id' => $atendente->id, 'tenant_id' => $this->tenant->id]);
        $this->assertDatabaseMissing('model_has_roles', ['model_id' => $user->id, 'role_id' => $gerente->id]);
    }

    public function test_only_users_of_the_tenant_are_listed(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $ours = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $otherTenant = Tenant::create(['name' => 'Outro', 'slug' => 'outro', 'whatsapp_number' => '5511888887777']);
        $theirs = User::factory()->create(['tenant_id' => $otherTenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->manager()
            ->assertCanSeeTableRecords([$ours])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_support_central_user_cannot_see_the_relation_manager(): void
    {
        $this->assertTrue(UsersRelationManager::canViewForRecord($this->tenant, EditTenant::class));

        $this->actingAs(User::factory()->create(['tenant_id' => null, 'central_role' => CentralRole::Support]));

        $this->assertFalse(UsersRelationManager::canViewForRecord($this->tenant, EditTenant::class));
    }

    public function test_the_last_admin_of_a_tenant_cannot_be_deleted(): void
    {
        $admin = $this->tenantRole('Admin');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $onlyAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $onlyAdmin->assignRole($admin);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->manager()->assertActionHidden(TestAction::make('delete')->table($onlyAdmin));

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $secondAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $secondAdmin->assignRole($admin);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->manager()->assertActionVisible(TestAction::make('delete')->table($onlyAdmin));
    }

    public function test_deleting_a_non_admin_user_works(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($this->tenantRole('Atendente'));
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->manager()
            ->callAction(TestAction::make('delete')->table($user))
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('model_has_roles', ['model_id' => $user->id]);
    }
}
