<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Models\TenantFiscalSettings;
use Illuminate\Support\Facades\Http;

/**
 * Fixtures da Fase 1.1 (cadastro fiscal): tenant com ou sem a feature
 * `nfe_emissao`, dados fiscais completos e o IBGE simulado. Usar junto de
 * CreatesTenantWithFeatures, CreatesSaleFixtures (produto/forma de pagamento)
 * e do contexto de tenant (actAsTenant).
 */
trait CreatesFiscalFixtures
{
    /** CNPJ válido (dígitos verificadores corretos). */
    protected const VALID_CNPJ = '11222333000181';

    protected function makeFiscalTenant(string $slug, bool $withNfe = true, bool $withAddress = true): Tenant
    {
        $plan = $withNfe ? $this->planWithAllFeaturesAndNfe() : $this->planWithAllFeatures();

        return Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => 'active',
            'whatsapp_number' => '5511999999999',
            'plan_id' => $plan->id,
            ...($withAddress ? [
                'cnpj' => self::VALID_CNPJ,
                'zip_code' => '01310100',
                'street' => 'Avenida Paulista',
                'number' => '1000',
                'neighborhood' => 'Bela Vista',
                'city' => 'São Paulo',
                'state' => 'SP',
            ] : []),
        ]);
    }

    /**
     * Dados fiscais completos do tenant (credenciais incluídas).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function completeFiscalSettings(Tenant $tenant, array $overrides = []): TenantFiscalSettings
    {
        return TenantFiscalSettings::query()->withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            array_merge([
                'legal_name' => 'Pizzaria Teste LTDA',
                'state_registration' => '123456789',
                'tax_regime' => 'simples_nacional',
                'state_uf' => 'SP',
                'city_ibge_code' => 3550308,
                'nfce_series' => 1,
                'environment' => 'test',
                'nfeio_company_id' => 'company-123',
                'nfeio_api_key' => 'chave-secreta-da-api',
                'nfeio_webhook_secret' => 'segredo-do-webhook',
            ], $overrides),
        );
    }

    /**
     * @return array<string, string|int>
     */
    protected function completeProductFiscalData(): array
    {
        return ['ncm' => '19059090', 'cfop' => '5102', 'csosn' => '102', 'origin_code' => '0', 'commercial_unit' => 'UN'];
    }

    protected function fakeIbge(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'servicodados.ibge.gov.br/*/estados?*' => Http::response([['id' => 35, 'sigla' => 'SP', 'nome' => 'São Paulo']]),
            'servicodados.ibge.gov.br/*/estados/SP/municipios' => Http::response([
                ['id' => 3550308, 'nome' => 'São Paulo'],
                ['id' => 3509502, 'nome' => 'Campinas'],
            ]),
        ]);
    }
}
