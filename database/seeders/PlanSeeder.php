<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Support\FeatureKey;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Operação básica de pedidos (Central de Pedidos, histórico, configurações,
        // linhas de produção, usuários/permissões) não é upsell — todo tenant
        // precisa disso pra funcionar, então entra no Essencial junto do cardápio.
        $essencial = Plan::query()->updateOrCreate(
            ['slug' => 'essencial'],
            ['name' => 'Essencial', 'description' => 'Cardápio digital, configurações do estabelecimento e operação de pedidos.', 'is_active' => true, 'display_order' => 1],
        );
        $essencial->features()->sync(
            Feature::query()->whereIn('key', [
                FeatureKey::CARDAPIO_DIGITAL,
                FeatureKey::CONFIGURACOES_ESTABELECIMENTO,
                FeatureKey::CENTRAL_DE_PEDIDOS,
                FeatureKey::HISTORICO_PEDIDOS,
                FeatureKey::CONFIGURACOES_PEDIDOS,
                FeatureKey::LINHAS_PRODUCAO,
                FeatureKey::USUARIOS_PERMISSOES,
                FeatureKey::RELATORIOS,
            ])->pluck('id'),
        );

        $completo = Plan::query()->updateOrCreate(
            ['slug' => 'completo'],
            ['name' => 'Completo', 'description' => 'Essencial + PDV, estoque e NF-e (liberados conforme entrarem em disponibilidade).', 'is_active' => true, 'display_order' => 2],
        );
        $completo->features()->sync(Feature::query()->pluck('id'));

        // Tenants provisionados antes da existência de planos (RN-40) entram
        // no Essencial por padrão — nunca deixar um tenant sem plano atribuído.
        Tenant::query()->whereNull('plan_id')->update(['plan_id' => $essencial->id]);
    }
}
