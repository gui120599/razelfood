<?php

namespace Tests\Concerns;

use App\Models\Feature;
use App\Models\Plan;
use App\Support\FeatureKey;

/**
 * Desde o catálogo de features/planos (RN-39 a RN-44), qualquer teste que
 * monta a página de um Resource/Page com GatedByFeature precisa que o
 * tenant tenha um plano com a feature exigida — senão canAccess() barra
 * com 403 antes do componente montar. Ver .ai/rules/feature.md.
 */
trait CreatesTenantWithFeatures
{
    protected function planWithAllFeatures(): Plan
    {
        $plan = Plan::create(['name' => 'Completo (teste)', 'slug' => 'completo-teste-'.uniqid()]);

        $keys = [
            FeatureKey::CARDAPIO_DIGITAL,
            FeatureKey::CONFIGURACOES_ESTABELECIMENTO,
            FeatureKey::CENTRAL_DE_PEDIDOS,
            FeatureKey::HISTORICO_PEDIDOS,
            FeatureKey::CONFIGURACOES_PEDIDOS,
            FeatureKey::LINHAS_PRODUCAO,
            FeatureKey::USUARIOS_PERMISSOES,
            FeatureKey::RELATORIOS,
        ];

        foreach ($keys as $key) {
            $feature = Feature::firstOrCreate(['key' => $key], ['name' => $key, 'is_available' => true]);
            $plan->features()->attach($feature);
        }

        return $plan;
    }

    /**
     * Plano com todas as features "always on" + `pdv` (venda/fechamento de
     * conta, Fase 1.0). Separado de planWithAllFeatures() para os testes
     * antigos continuarem sem PDV (tenant sem a feature: nada novo aparece).
     */
    protected function planWithAllFeaturesAndPdv(): Plan
    {
        $plan = $this->planWithAllFeatures();

        $pdv = Feature::firstOrCreate(['key' => FeatureKey::PDV], ['name' => 'PDV / Venda', 'is_available' => true]);
        $pdv->update(['is_available' => true]);
        $plan->features()->attach($pdv);

        return $plan;
    }

    /**
     * Plano com todas as features "always on" + `nfe_emissao` (cadastro fiscal e
     * NFC-e, Fase 1.1). A feature segue indisponível no seeder até a Fase 1.2;
     * aqui ela é criada disponível só para exercitar a UI fiscal.
     */
    protected function planWithAllFeaturesAndNfe(): Plan
    {
        $plan = $this->planWithAllFeatures();

        $nfe = Feature::firstOrCreate(['key' => FeatureKey::NFE_EMISSAO], ['name' => 'Emissão de NFC-e', 'is_available' => true]);
        $nfe->update(['is_available' => true]);
        $plan->features()->attach($nfe);

        return $plan;
    }
}
