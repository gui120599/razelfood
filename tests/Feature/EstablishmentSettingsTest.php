<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Filament\Tenant\Pages\EstablishmentSettings;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RF-30/RF-34: o Admin do tenant mantém a própria identidade do
 * estabelecimento (nome, WhatsApp, logo, cor) sem depender da Razel Tec.
 */
class EstablishmentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Autorização por role/policy (Shield) não é o alvo destes testes.
        Gate::before(fn () => true);

        // Selects de UF/Cidade do form são alimentados pela API do IBGE.
        // A busca de CEP (ViaCEP) é fakeada só nos testes que a exercitam.
        Http::fake([
            'servicodados.ibge.gov.br/*/estados?*' => Http::response([
                ['id' => 35, 'sigla' => 'SP', 'nome' => 'São Paulo'],
            ]),
            'servicodados.ibge.gov.br/*/estados/SP/municipios' => Http::response([
                ['id' => 3550308, 'nome' => 'São Paulo'],
            ]),
        ]);

        $feature = Feature::create(['key' => FeatureKey::CONFIGURACOES_ESTABELECIMENTO, 'name' => 'Configurações do Estabelecimento', 'is_available' => true]);
        $plan = Plan::create(['name' => 'Completo', 'slug' => 'completo']);
        $plan->features()->attach($feature);

        $this->tenant = Tenant::create([
            'name' => 'Empório da Pizza',
            'slug' => 'emporio',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
            'watermark_height' => 288,
            'plan_id' => $plan->id,
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));

        $this->actingAs(User::factory()->create(['tenant_id' => $this->tenant->id]));
    }

    public function test_admin_can_update_name_whatsapp_and_color(): void
    {
        Livewire::test(EstablishmentSettings::class)
            ->assertFormSet(['name' => 'Empório da Pizza'])
            ->fillForm([
                'name' => 'Empório da Pizza Premium',
                'whatsapp_number' => '(11) 98888-7777',
                'primary_color' => '#ff6600',
                'watermark_height' => 320,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $tenant = $this->tenant->fresh();
        $this->assertSame('Empório da Pizza Premium', $tenant->name);
        $this->assertSame('11988887777', $tenant->whatsapp_number);
        $this->assertSame(320, $tenant->watermark_height);
    }

    public function test_admin_can_upload_a_favicon_for_the_public_menu(): void
    {
        Storage::fake('public');

        Livewire::test(EstablishmentSettings::class)
            ->fillForm(['favicon_path' => UploadedFile::fake()->image('favicon.png', 512, 512)])
            ->call('save')
            ->assertHasNoFormErrors();

        $faviconPath = $this->tenant->fresh()->favicon_path;

        $this->assertNotNull($faviconPath);
        $this->assertStringStartsWith('tenants/favicons/', $faviconPath);
        Storage::disk('public')->assertExists($faviconPath);
    }

    public function test_admin_can_enable_and_upload_a_logo_for_print_documents(): void
    {
        Storage::fake('public');

        Livewire::test(EstablishmentSettings::class)
            ->fillForm(['show_logo_on_prints' => true])
            ->fillForm(['print_logo_path' => UploadedFile::fake()->image('comanda.png', 400, 200)])
            ->call('save')
            ->assertHasNoFormErrors();

        $tenant = $this->tenant->fresh();

        $this->assertTrue($tenant->show_logo_on_prints);
        $this->assertNotNull($tenant->print_logo_path);
        $this->assertStringStartsWith('tenants/print/', $tenant->print_logo_path);
        Storage::disk('public')->assertExists($tenant->print_logo_path);
    }

    public function test_admin_can_save_cnpj_and_address(): void
    {
        Livewire::test(EstablishmentSettings::class)
            ->fillForm([
                'cnpj' => '11.222.333/0001-81',
                'zip_code' => '01001-000',
                'street' => 'Praça da Sé',
                'number' => '100',
                'complement' => 'lado ímpar',
                'neighborhood' => 'Sé',
                'state' => 'SP',
            ])
            ->fillForm(['city' => 'São Paulo'])
            ->call('save')
            ->assertHasNoFormErrors();

        $tenant = $this->tenant->fresh();
        $this->assertSame('11222333000181', $tenant->cnpj);
        $this->assertSame('01001000', $tenant->zip_code);
        $this->assertSame('Praça da Sé', $tenant->street);
        $this->assertSame('SP', $tenant->state);
    }

    public function test_cep_lookup_fills_the_address_fields_including_uf_and_city(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response([
            'logradouro' => 'Praça da Sé',
            'bairro' => 'Sé',
            'localidade' => 'São Paulo',
            'uf' => 'SP',
        ])]);

        Livewire::test(EstablishmentSettings::class)
            ->fillForm(['zip_code' => '01001-000'])
            ->assertFormSet([
                'street' => 'Praça da Sé',
                'neighborhood' => 'Sé',
                'city' => 'São Paulo',
                'state' => 'SP',
            ]);
    }

    public function test_cep_lookup_does_not_clobber_the_form_when_not_found(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response(['erro' => true])]);

        Livewire::test(EstablishmentSettings::class)
            ->fillForm(['street' => 'Rua Digitada', 'zip_code' => '99999-999'])
            ->assertFormSet(['street' => 'Rua Digitada'])
            ->assertNotified();
    }

    public function test_city_options_depend_on_the_selected_uf(): void
    {
        Livewire::test(EstablishmentSettings::class)
            ->fillForm(['state' => 'SP', 'city' => 'São Paulo'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('São Paulo', $this->tenant->fresh()->city);
        $this->assertSame('SP', $this->tenant->fresh()->state);
    }

    public function test_invalid_cnpj_is_rejected(): void
    {
        Livewire::test(EstablishmentSettings::class)
            ->fillForm(['cnpj' => '11.222.333/0001-99'])
            ->call('save')
            ->assertHasFormErrors(['cnpj']);
    }

    public function test_saving_invalidates_the_tenant_slug_cache(): void
    {
        Cache::put("tenant:slug:{$this->tenant->slug}", $this->tenant, now()->addMinutes(10));

        Livewire::test(EstablishmentSettings::class)
            ->fillForm(['name' => 'Nome Novo'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(Cache::has("tenant:slug:{$this->tenant->slug}"));
    }

    public function test_page_is_hidden_when_tenant_lacks_the_feature(): void
    {
        $planWithout = Plan::create(['name' => 'Básico', 'slug' => 'basico']);
        $bare = Tenant::create([
            'name' => 'Sem feature',
            'slug' => 'sem-feature',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511777776666',
            'plan_id' => $planWithout->id,
        ]);
        CurrentTenant::set($bare);
        URL::defaults(['tenant' => $bare->slug]);
        $this->actingAs(User::factory()->create(['tenant_id' => $bare->id]));

        $this->assertFalse(EstablishmentSettings::canAccess());
        $this->assertFalse(EstablishmentSettings::shouldRegisterNavigation());
    }
}
