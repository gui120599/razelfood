<?php

namespace Tests\Feature\Fiscal\Lifecycle;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Filament\Tenant\Pages\FiscalSettings;
use App\Models\Tenant;
use App\Models\TenantFiscalSettings;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * URL do webhook por tenant na página "Dados fiscais": gerada pelo Admin, opaca (não é o slug)
 * e regenerar invalida a anterior.
 */
class WebhookUrlSettingsTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesNfceFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        $this->fakeIbge();
        $this->tenant = $this->makeNfceTenant('lazzopizza');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(SeedDefaultTenantRoles::class)($this->tenant);
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);
        $this->actingAs($user);
    }

    private function token(): ?string
    {
        return TenantFiscalSettings::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->value('webhook_token');
    }

    public function test_the_page_guides_the_admin_to_generate_the_url_and_then_shows_it(): void
    {
        $this->actingAsRole('Admin');

        Livewire::test(FiscalSettings::class)
            ->assertSee('Webhook da NFE.io')
            ->assertSee('Ainda não há URL')
            ->callAction('generateWebhookToken')
            ->assertNotified('URL do webhook gerada');

        $token = $this->token();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', $token);
        $this->assertStringNotContainsString('lazzopizza', $token);

        Livewire::test(FiscalSettings::class)->assertSee(route('webhooks.nfeio', ['token' => $token]), false);
    }

    public function test_regenerating_invalidates_the_previous_url(): void
    {
        $this->actingAsRole('Admin');
        Livewire::test(FiscalSettings::class)->callAction('generateWebhookToken');
        $old = $this->token();
        Http::preventStrayRequests();

        Livewire::test(FiscalSettings::class)->callAction('generateWebhookToken');
        $new = $this->token();

        $this->assertNotSame($old, $new);
        $this->forgetTenantContext();
        $this->postNfeIoWebhook($old, ['id' => 'x', 'status' => 'Issued'])->assertNotFound();
        $this->postNfeIoWebhook($new, ['id' => 'x', 'status' => 'Issued'])->assertOk();
    }

    public function test_saving_the_form_keeps_the_token_and_the_token_is_never_serialized(): void
    {
        $this->actingAsRole('Admin');
        Livewire::test(FiscalSettings::class)->callAction('generateWebhookToken');
        $token = $this->token();

        Livewire::test(FiscalSettings::class)->call('save');

        $this->assertSame($token, $this->token());
        $settings = TenantFiscalSettings::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->first();
        $this->assertArrayNotHasKey('webhook_token', $settings->toArray());
        $this->assertArrayNotHasKey('nfeio_webhook_secret', $settings->toArray());
    }

    public function test_other_roles_cannot_generate_the_url(): void
    {
        $this->actingAsRole('Gerente');

        Livewire::test(FiscalSettings::class)->assertForbidden();
        $this->assertNull($this->token());
    }

    public function test_two_tenants_get_different_tokens(): void
    {
        $other = $this->makeNfceTenant('emporiodapizza', ['nfeio_company_id' => 'empresa-B'], cnpj: '45723174000110');
        $this->actAsTenant($this->tenant);
        app(SeedDefaultTenantRoles::class)($this->tenant);
        $this->actingAsRole('Admin');
        Livewire::test(FiscalSettings::class)->callAction('generateWebhookToken');

        $this->assertNotNull($this->token());
        $this->assertNull(TenantFiscalSettings::query()->withoutGlobalScopes()->where('tenant_id', $other->id)->value('webhook_token'));
    }
}
