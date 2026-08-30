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
}
