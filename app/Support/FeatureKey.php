<?php

namespace App\Support;

/**
 * Chaves técnicas do catálogo de features (RN-39), usadas tanto no seed
 * quanto no gating de Resources do painel do tenant — evita string solta
 * divergindo entre os dois lugares.
 */
final class FeatureKey
{
    public const CARDAPIO_DIGITAL = 'cardapio_digital';

    public const CONFIGURACOES_ESTABELECIMENTO = 'configuracoes_estabelecimento';

    public const CENTRAL_DE_PEDIDOS = 'central_de_pedidos';

    public const CONFIGURACOES_PEDIDOS = 'configuracoes_pedidos';

    public const HISTORICO_PEDIDOS = 'historico_pedidos';

    public const LINHAS_PRODUCAO = 'linhas_producao';

    public const USUARIOS_PERMISSOES = 'usuarios_permissoes';

    public const RELATORIOS = 'relatorios';

    public const PDV = 'pdv';

    public const ESTOQUE = 'estoque';

    public const NFE_EMISSAO = 'nfe_emissao';

    public const NFE_ENTRADA = 'nfe_entrada';
}
