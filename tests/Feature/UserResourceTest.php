<?php

namespace Tests\Feature;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\Users\Pages\CreateUser;
use App\Filament\Tenant\Resources\Users\Pages\EditUser;
use App\Filament\Tenant\Resources\Users\Pages\ListUsers;
use App\Filament\Tenant\Resources\Users\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

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

        // SeedDefaultTenantRoles não cria as permissões de UserResource (só
        // Order/Kitchen/OrderSettings/ProductionLine) — num banco de testes
        // "limpo", sem um shield:generate --all já rodado antes, o Admin não
        // tem "ViewAny:User" mesmo com syncPermissions(Permission::all()),
        // porque a permissão nunca existiu como registro. Ver mesmo padrão em
        // ProductTableFilterPreferencesTest.
        collect(FilamentShield::getResources()[UserResource::class]['permissions'] ?? [])
            ->pluck('key')
            ->filter()
            ->each(fn (string $key) => Permission::findOrCreate($key));

        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin->assignRole('Admin');
        $this->admin->syncPermissions(Permission::all());
        $this->actingAs($this->admin);
    }

    public function test_create_assigns_role_scoped_to_the_current_tenant(): void
    {
        $gerente = Role::where('name', 'Gerente')->where('tenant_id', $this->tenant->id)->firstOrFail();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Nova Gerente',
                'email' => 'gerente@example.com',
                'password' => 'password',
                'roleIds' => [$gerente->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'gerente@example.com')->firstOrFail();

        $this->assertSame($this->tenant->id, $user->tenant_id);
        $this->assertTrue($user->hasRole('Gerente'));

        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $gerente->id,
            'model_id' => $user->id,
            'model_type' => User::class,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_edit_replaces_the_previously_assigned_roles(): void
    {
        $gerente = Role::where('name', 'Gerente')->where('tenant_id', $this->tenant->id)->firstOrFail();
        $atendente = Role::where('name', 'Atendente')->where('tenant_id', $this->tenant->id)->firstOrFail();

        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole('Gerente');

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->assertFormSet(['roleIds' => [$gerente->id]])
            ->fillForm(['roleIds' => [$atendente->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertTrue($user->hasRole('Atendente'));
        $this->assertFalse($user->hasRole('Gerente'));
    }

    public function test_delete_action_is_hidden_for_the_currently_logged_in_user(): void
    {
        Livewire::test(EditUser::class, ['record' => $this->admin->id])
            ->assertActionHidden('delete');
    }

    public function test_user_from_another_tenant_is_not_listed_or_editable(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Outro Tenant',
            'slug' => 'outro-tenant',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511988888888',
        ]);

        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$this->admin])
            ->assertCanNotSeeTableRecords([$otherUser]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(EditUser::class, ['record' => $otherUser->id]);
    }
}
