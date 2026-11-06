<?php

namespace App\Models;

use App\Enums\FiscalEnvironment;
use App\Enums\TaxRegime;
use App\Models\Concerns\TenantScopedModel;

/**
 * Dados fiscais do tenant (1:1) para a emissão de NFC-e. Guarda as
 * credenciais da NFE.io criptografadas e NUNCA as expõe em serialização
 * (`$hidden`). CNPJ e endereço do emitente ficam em `tenants`.
 */
class TenantFiscalSettings extends TenantScopedModel
{
    protected $table = 'tenant_fiscal_settings';

    protected $fillable = [
        'tenant_id',
        'legal_name',
        'state_registration',
        'tax_regime',
        'state_uf',
        'city_ibge_code',
        'provider',
        'nfeio_company_id',
        'nfeio_api_key',
        'nfeio_webhook_secret',
        'environment',
        'nfce_series',
        'approximate_tax_percent',
        'enabled_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'nfeio_api_key',
        'nfeio_webhook_secret',
        'webhook_token',
    ];

    protected function casts(): array
    {
        return [
            'tax_regime' => TaxRegime::class,
            'environment' => FiscalEnvironment::class,
            'city_ibge_code' => 'integer',
            'nfce_series' => 'integer',
            'approximate_tax_percent' => 'decimal:2',
            'enabled_at' => 'datetime',
            'nfeio_api_key' => 'encrypted',
            'nfeio_webhook_secret' => 'encrypted',
        ];
    }

    public function hasCredentials(): bool
    {
        // Só presença: valor cru, sem decifrar (APP_KEY trocada não pode derrubar a tela).
        return filled($this->nfeio_company_id) && filled($this->getRawOriginal('nfeio_api_key'));
    }

    public function isEnabled(): bool
    {
        return $this->enabled_at !== null;
    }
}
