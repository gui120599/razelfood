<?php

namespace Tests\Feature\Central;

use App\Enums\CentralRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RN-44: o gestor da plataforma (central_role Platform) gerencia os
 * usuários internos da Razel Tec e o papel de cada um. Suporte não vê o
 * recurso; usuários dos tenants nunca aparecem aqui.
 */
class CentralUserResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('central'));
    }

    private function centralUser(?CentralRole $role): User
    {
        return User::factory()->create(['tenant_id' => null, 'central_role' => $role]);
    }

    public function test_platform_admin_can_open_the_resource(): void
    {
        $this->actingAs($this->centralUser(CentralRole::Platform));

        $this->get(UserResource::getUrl('index'))->assertOk();
    }

    public function test_support_user_cannot_open_the_resource(): void
    {
        $this->actingAs($this->centralUser(CentralRole::Support));

        $this->get(UserResource::getUrl('index'))->assertForbidden();
    }

    public function test_central_user_without_a_role_cannot_open_the_resource(): void
    {
        $this->actingAs($this->centralUser(null));

        $this->get(UserResource::getUrl('index'))->assertForbidden();
    }

    public function test_tenant_user_cannot_open_the_resource(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'whatsapp_number' => '5511999999999']);
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));

        $this->get(UserResource::getUrl('index'))->assertForbidden();
    }

    public function test_platform_admin_creates_an_internal_user_with_a_hashed_password(): void
    {
        $this->actingAs($this->centralUser(CentralRole::Platform));

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Novo Suporte',
                'email' => 'suporte@razeltec.com.br',
                'password' => 'segredo123',
                'central_role' => CentralRole::Support->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'suporte@razeltec.com.br')->firstOrFail();

        $this->assertNull($user->tenant_id);
        $this->assertSame(CentralRole::Support, $user->central_role);
        $this->assertTrue(Hash::check('segredo123', $user->password));
    }

    public function test_email_must_be_unique(): void
    {
        $this->actingAs($this->centralUser(CentralRole::Platform));
        User::factory()->create(['email' => 'existente@razeltec.com.br', 'tenant_id' => null, 'central_role' => CentralRole::Support]);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Duplicado',
                'email' => 'existente@razeltec.com.br',
                'password' => 'segredo123',
                'central_role' => CentralRole::Support->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    public function test_editing_changes_the_role_and_keeps_the_password_when_left_blank(): void
    {
        $this->actingAs($this->centralUser(CentralRole::Platform));
        $target = $this->centralUser(CentralRole::Support);
        $originalHash = $target->password;

        Livewire::test(EditUser::class, ['record' => $target->id])
            ->fillForm(['central_role' => CentralRole::Platform->value, 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $target->refresh();
        $this->assertSame(CentralRole::Platform, $target->central_role);
        $this->assertSame($originalHash, $target->password);
    }

    public function test_editing_replaces_the_password_when_provided(): void
    {
        $this->actingAs($this->centralUser(CentralRole::Platform));
        $target = $this->centralUser(CentralRole::Support);

        Livewire::test(EditUser::class, ['record' => $target->id])
            ->fillForm(['password' => 'nova-senha-123'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('nova-senha-123', $target->fresh()->password));
    }

    public function test_only_internal_users_are_listed(): void
    {
        $this->actingAs($me = $this->centralUser(CentralRole::Platform));
        $internal = $this->centralUser(CentralRole::Support);

        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'whatsapp_number' => '5511999999999']);
        $tenantUser = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$me, $internal])
            ->assertCanNotSeeTableRecords([$tenantUser]);
    }

    public function test_cannot_edit_a_tenant_user_through_the_central_resource(): void
    {
        $this->actingAs($this->centralUser(CentralRole::Platform));
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'whatsapp_number' => '5511999999999']);
        $tenantUser = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(EditUser::class, ['record' => $tenantUser->id]);
    }

    public function test_the_logged_in_user_cannot_delete_or_demote_themselves(): void
    {
        $this->actingAs($me = $this->centralUser(CentralRole::Platform));

        Livewire::test(EditUser::class, ['record' => $me->id])
            ->assertActionHidden('delete')
            ->assertFormFieldIsDisabled('central_role');
    }

    public function test_the_role_field_is_editable_for_other_users(): void
    {
        $this->actingAs($this->centralUser(CentralRole::Platform));
        $other = $this->centralUser(CentralRole::Support);

        Livewire::test(EditUser::class, ['record' => $other->id])
            ->assertFormFieldIsEnabled('central_role')
            ->assertActionVisible('delete');
    }
}
