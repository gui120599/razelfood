<?php

namespace Tests\Feature;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\Clients\ClientResource;
use App\Filament\Tenant\Resources\Clients\Pages\CreateClient;
use App\Filament\Tenant\Resources\Clients\Pages\EditClient;
use App\Filament\Tenant\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ClientResourceTest extends TestCase
{
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
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(SeedDefaultTenantRoles::class)($this->tenant);

        // SeedDefaultTenantRoles não cria as permissões de ClientResource (só
        // Order/Kitchen/OrderSettings/ProductionLine) — num banco de testes
        // "limpo", sem um shield:generate --all já rodado antes, o Admin não
        // tem "ViewAny:Client" mesmo com syncPermissions(Permission::all()),
        // porque a permissão nunca existiu como registro. Ver mesmo padrão em
        // ProductTableFilterPreferencesTest.
        collect(FilamentShield::getResources()[ClientResource::class]['permissions'] ?? [])
            ->pluck('key')
            ->filter()
            ->each(fn (string $key) => Permission::findOrCreate($key));

        $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $admin->assignRole('Admin');
        $admin->syncPermissions(Permission::all());
        $this->actingAs($admin);
    }

    public function test_create_normalizes_a_formatted_phone_number(): void
    {
        Livewire::test(CreateClient::class)
            ->fillForm([
                'name' => 'João Cliente',
                'phone' => '(11) 99999-8888',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', [
            'tenant_id' => $this->tenant->id,
            'name' => 'João Cliente',
            'phone' => '11999998888',
        ]);
    }

    public function test_phone_must_be_unique_within_the_tenant(): void
    {
        Client::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cliente Existente',
            'phone' => '11999998888',
        ]);

        Livewire::test(CreateClient::class)
            ->fillForm([
                'name' => 'Outro Cliente',
                'phone' => '11999998888',
            ])
            ->call('create')
            ->assertHasFormErrors(['phone' => 'unique']);
    }

    public function test_edit_updates_address_fields(): void
    {
        $client = Client::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cliente Teste',
            'phone' => '11999998888',
        ]);

        Livewire::test(EditClient::class, ['record' => $client->id])
            ->fillForm([
                'neighborhood' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $client->refresh();
        $this->assertSame('Centro', $client->neighborhood);
        $this->assertSame('São Paulo', $client->city);
    }

    public function test_zip_code_lookup_fills_address_fields_via_viacep(): void
    {
        Http::fake([
            'viacep.com.br/*' => Http::response([
                'logradouro' => 'Praça da Sé',
                'bairro' => 'Sé',
                'localidade' => 'São Paulo',
                'uf' => 'SP',
            ]),
        ]);

        Livewire::test(CreateClient::class)
            ->fillForm(['name' => 'João Cliente', 'phone' => '11999998888'])
            ->set('data.zip_code', '01001-000')
            ->assertFormSet([
                'street' => 'Praça da Sé',
                'neighborhood' => 'Sé',
                'city' => 'São Paulo',
                'state' => 'SP',
            ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'viacep.com.br/ws/01001000/json/'));
    }

    public function test_zip_code_lookup_notifies_and_leaves_fields_editable_when_cep_is_not_found(): void
    {
        Http::fake([
            'viacep.com.br/*' => Http::response(['erro' => true]),
        ]);

        Livewire::test(CreateClient::class)
            ->fillForm(['name' => 'João Cliente', 'phone' => '11999998888'])
            ->set('data.zip_code', '00000-000')
            ->assertNotified('CEP não encontrado')
            ->assertFormSet(['street' => null]);
    }

    public function test_client_from_another_tenant_is_not_listed(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Outro Tenant',
            'slug' => 'outro-tenant',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511988888888',
        ]);

        $otherClient = Client::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Cliente de Outro Tenant',
            'phone' => '11988887777',
        ]);

        $ownClient = Client::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cliente do Tenant Atual',
            'phone' => '11999998888',
        ]);

        Livewire::test(ListClients::class)
            ->assertCanSeeTableRecords([$ownClient])
            ->assertCanNotSeeTableRecords([$otherClient]);
    }
}
