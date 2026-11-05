<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Support\FeatureKey;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $features = [
            ['key' => FeatureKey::CARDAPIO_DIGITAL, 'name' => 'Cardápio Digital', 'description' => 'Cardápio público, categorias e produtos.', 'is_available' => true, 'display_order' => 1],
            ['key' => FeatureKey::CONFIGURACOES_ESTABELECIMENTO, 'name' => 'Configurações do Estabelecimento', 'description' => 'Horários, opções de entrega, setores e formas de pagamento.', 'is_available' => true, 'display_order' => 2],
            ['key' => FeatureKey::CENTRAL_DE_PEDIDOS, 'name' => 'Central de Pedidos', 'description' => 'Kanban de acompanhamento de pedidos em tempo real (Central de Pedidos/cozinha).', 'is_available' => true, 'display_order' => 3],
            ['key' => FeatureKey::HISTORICO_PEDIDOS, 'name' => 'Histórico de Pedidos', 'description' => 'Listagem e detalhe de pedidos já concluídos/cancelados.', 'is_available' => true, 'display_order' => 4],
            ['key' => FeatureKey::CONFIGURACOES_PEDIDOS, 'name' => 'Configurações de Pedidos', 'description' => 'Limiares de alerta de atenção/atraso e regra de bairro não configurado.', 'is_available' => true, 'display_order' => 5],
            ['key' => FeatureKey::LINHAS_PRODUCAO, 'name' => 'Linhas de Produção', 'description' => 'Agrupamento de categorias por linha de produção, usado no filtro da Central de Pedidos.', 'is_available' => true, 'display_order' => 6],
            ['key' => FeatureKey::USUARIOS_PERMISSOES, 'name' => 'Usuários e Permissões', 'description' => 'Gestão de papéis e permissões da equipe do tenant (Filament Shield).', 'is_available' => true, 'display_order' => 7],
            ['key' => FeatureKey::RELATORIOS, 'name' => 'Relatórios', 'description' => 'Dashboard de indicadores operacionais por período (pedidos por status/dia/hora/origem, formas de pagamento, motivos de cancelamento, mais vendidos) e exportação CSV.', 'is_available' => true, 'display_order' => 8],
            ['key' => FeatureKey::PDV, 'name' => 'PDV / Venda', 'description' => 'Fechamento de conta: venda que agrega pedidos e itens avulsos, com pagamentos recebidos e troco. Sem caixa, fiado ou financeiro.', 'is_available' => true, 'display_order' => 9],
            ['key' => FeatureKey::ESTOQUE, 'name' => 'Controle de Estoque', 'description' => 'Controle de estoque avançado. Reservado — sem implementação funcional ainda.', 'is_available' => false, 'display_order' => 10],
            ['key' => FeatureKey::NFE_EMISSAO, 'name' => 'Emissão de NFC-e', 'description' => 'Emissão de nota fiscal ao consumidor (NFC-e) via NFE.io. Em escopo, desligada até a Fase 1.2 do plano de migração ser entregue.', 'is_available' => false, 'display_order' => 11],
            ['key' => FeatureKey::NFE_ENTRADA, 'name' => 'Entrada de NF-e', 'description' => 'Registro de nota fiscal de entrada. Reservado — sem implementação funcional ainda.', 'is_available' => false, 'display_order' => 12],
            ['key' => FeatureKey::STONE_CONNECT, 'name' => 'Cobrança em Maquininha (Stone Connect)', 'description' => 'Cobrança de pedidos e contas em maquininha Stone. Em escopo, desligada até a Fase 2 do plano de migração ser entregue.', 'is_available' => false, 'display_order' => 13],
        ];

        foreach ($features as $feature) {
            Feature::query()->updateOrCreate(['key' => $feature['key']], $feature);
        }
    }
}
