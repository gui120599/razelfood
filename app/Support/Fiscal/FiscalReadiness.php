<?php

namespace App\Support\Fiscal;

use App\Enums\TaxRegime;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\TenantFiscalSettings;
use App\Rules\ValidCnpj;
use Illuminate\Support\Facades\Validator;

/**
 * Checklist de pré-requisitos da NFC-e (doc migracao-nfeio §5.1): o que falta
 * no tenant, nos produtos e nas formas de pagamento. Não faz HTTP e não
 * depende do tenant "atual" (consulta sempre pelo tenant informado), então
 * serve ao painel, a jobs e ao painel central.
 */
class FiscalReadiness
{
    private const SAMPLE_SIZE = 10;

    public function __construct(private readonly ResolveProductFiscalData $resolveProductFiscalData) {}

    public function forTenant(Tenant $tenant): FiscalReadinessReport
    {
        return new FiscalReadinessReport([
            ...$this->tenantIssues($tenant),
            ...$this->productIssues($tenant),
            ...$this->paymentIssues($tenant),
        ]);
    }

    /**
     * @return list<FiscalIssue>
     */
    private function tenantIssues(Tenant $tenant): array
    {
        // O Tenant do cache de resolução (`tenant:slug:*`) pode estar defasado em relação a
        // edições do painel central: CNPJ e endereço do emitente vêm sempre do banco.
        $tenant = Tenant::query()->find($tenant->id) ?? $tenant;

        $issues = [];
        $add = function (string $message) use (&$issues): void {
            $issues[] = new FiscalIssue(FiscalIssue::SECTION_TENANT, $message);
        };

        if (blank($tenant->cnpj)) {
            $add('Informe o CNPJ do estabelecimento (em Estabelecimento).');
        } elseif (Validator::make(['cnpj' => $tenant->cnpj], ['cnpj' => [new ValidCnpj]])->fails()) {
            $add('O CNPJ do estabelecimento é inválido.');
        }

        $missingAddress = collect([
            'zip_code' => 'CEP',
            'street' => 'logradouro',
            'number' => 'número',
            'neighborhood' => 'bairro',
            'city' => 'cidade',
            'state' => 'UF',
        ])->filter(fn (string $label, string $field): bool => blank($tenant->{$field}))->values();

        if ($missingAddress->isNotEmpty()) {
            $add('Complete o endereço do estabelecimento: '.$missingAddress->implode(', ').'.');
        }

        $settings = TenantFiscalSettings::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->first();

        if ($settings === null) {
            $add('Preencha os dados fiscais do estabelecimento.');

            return $issues;
        }

        if (blank($settings->legal_name)) {
            $add('Informe a razão social.');
        }

        if (blank($settings->state_registration)) {
            $add('Informe a Inscrição Estadual (ou ISENTO).');
        }

        if (! $settings->tax_regime->isSupported()) {
            $add(TaxRegime::unsupportedMessage());
        }

        if (blank($settings->state_uf)) {
            $add('Selecione a UF do estabelecimento nos dados fiscais.');
        } elseif (filled($tenant->state) && strcasecmp($settings->state_uf, $tenant->state) !== 0) {
            $add("A UF dos dados fiscais ({$settings->state_uf}) é diferente da UF do endereço ({$tenant->state}).");
        }

        if ($settings->city_ibge_code === null) {
            $add('Selecione a cidade do estabelecimento (código IBGE).');
        }

        if (($settings->nfce_series ?? 0) < 1) {
            $add('Informe a série da NFC-e.');
        }

        // Só presença: lê o valor cru para não decifrar (nem falhar com APP_KEY trocada).
        if (blank($settings->nfeio_company_id)) {
            $add('Informe o ID da empresa (companyId) na NFE.io.');
        }

        if (blank($settings->getRawOriginal('nfeio_api_key'))) {
            $add('Informe a chave de API da NFE.io.');
        }

        return $issues;
    }

    /**
     * @return list<FiscalIssue>
     */
    private function productIssues(Tenant $tenant): array
    {
        $incomplete = [];

        Product::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->select(['id', 'name', 'category_id', 'ncm', 'cfop', 'csosn', 'origin_code', 'commercial_unit'])
            ->with(['category' => fn ($query) => $query->withoutGlobalScope(TenantScope::class), 'category.parent' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)])
            ->chunkById(200, function ($products) use (&$incomplete): void {
                foreach ($products as $product) {
                    $missing = ($this->resolveProductFiscalData)($product)->missing();

                    if ($missing !== []) {
                        $incomplete[] = "{$product->name} (faltam: ".implode(', ', $missing).')';
                    }
                }
            });

        if ($incomplete === []) {
            return [];
        }

        return [new FiscalIssue(
            FiscalIssue::SECTION_PRODUCTS,
            count($incomplete) === 1 ? '1 produto sem dados fiscais completos.' : count($incomplete).' produtos sem dados fiscais completos.',
            array_slice($incomplete, 0, self::SAMPLE_SIZE),
            count($incomplete),
        )];
    }

    /**
     * @return list<FiscalIssue>
     */
    private function paymentIssues(Tenant $tenant): array
    {
        $names = PaymentOption::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->whereNull('fiscal_payment_code')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($names === []) {
            return [];
        }

        return [new FiscalIssue(
            FiscalIssue::SECTION_PAYMENTS,
            count($names) === 1 ? '1 forma de pagamento sem código fiscal.' : count($names).' formas de pagamento sem código fiscal.',
            array_slice($names, 0, self::SAMPLE_SIZE),
            count($names),
        )];
    }
}
