# Prioridade 4 — Painel da Cozinha (KDS) no RazelFood

> Parte do plano [migracao-funcionalidades-pizzaria-app-para-razelfood.md](migracao-funcionalidades-pizzaria-app-para-razelfood.md).
> **Data:** 2026-11-04 · **Status:** avaliação, nada implementado.
> Referência principal no Pizzaria-App: `docs/modulo-cozinha.md` (requisitos RF01–RF27, UML, regras §5), `app/Services/Cozinha/*`, `app/Filament/Cozinha/*`, `app/Enums/Cozinha/*`.

---

## 1. O que existe em cada sistema

| Capacidade | Pizzaria-App | RazelFood |
|---|---|---|
| Painel de pedidos (kanban por status do pedido) | `PainelPedidos` (admin) | `Kitchen` ("Central de Pedidos", `/painel/{slug}/cozinha`), `wire:poll.15s`, filtros por período/status/tipo/entregador/**linha de produção**, urgência por SLA, ações de avançar/despachar/cancelar/entregar, comanda |
| Setores de produção | `SetorProducao` (cor, ícone, ordem, tempo alvo/alerta, exige operador, explode unidades, permite pular início) | `ProductionLine` (só `name`) + `category_production_line` — **usado só como filtro** do kanban |
| Rota de produção (produto/categoria → etapas) | `RotaProducao`, `RotasProducaoService`, `RoteadorProducao` | ❌ |
| Itens de produção (por unidade e etapa, com snapshot) | `ProducaoItem`, `ProducaoEvento` (append-only) | ❌ |
| Status derivado de produção do pedido | `SincronizadorPedidoProducao` + `PedidoStatusGateway` (nunca retrocede o pedido) | ❌ |
| Tela da estação (Fila → Em preparo → Prontos) | `Filament/Cozinha/Pages/Estacao` | ❌ |
| Expedição | `Expedicao` (despacho, refazer com motivo) | ❌ |
| Tablet pareado por QR + operador | `DispositivoCozinha`, `PareamentoDispositivoService`, `OperadorCozinhaService`, middleware `IdentificaDispositivoCozinha` | ❌ |
| Ficha técnica viva | `FichaTecnica*`, `FichaTecnicaMontador` | ❌ |
| All-day count | `PainelCozinhaService` | ❌ |
| Alertas (novo, alterado após início, cancelado, atraso) | Cards com "Ciente", comando `cozinha:alertar-atrasos` | Parcial: urgência por SLA no card |
| Disparo por tempo (itens rápidos esperam) | `cozinha:liberar-itens` | ❌ |
| Tempo real | `CozinhaTempoReal` (contador de versão em cache; Reverb opcional) | Polling 15 s simples |
| Comunicação com o garçom | Notificação "pronto" + push | ❌ (não há garçom) |
| Relatório de produção | `RelatorioProducaoService` | ❌ |

## 2. O que o painel do garçom precisa da cozinha (entra na P3)

1. **Rodada visível no kanban** com identificação de mesa e rodada → já funciona porque rodada = `Order`; falta só o selo (Fase 3.2).
2. **Aviso de pronto** → ao `AdvanceOrderStatus` levar um pedido de mesa a `Ready`, notificar o `waiter_user_id` (notificação de banco do Filament no painel `garcom`) — pequeno, entra na Fase 3.2.
3. **Item cancelado pelo garçom após envio** → kanban mostra o pedido atualizado (já recalcula); destacar "alterado" é desejável (P4).

Conclusão: **o KDS completo não bloqueia o garçom**.

## 3. Regras do Pizzaria-App a preservar quando o KDS vier

| Regra | Classificação |
|---|---|
| Geração de produção **depois do commit**, idempotente, quando o pedido entra no status que libera a cozinha | [GERAL] |
| Resolução de rota: produto dispensa produção → nada; rota do produto → da categoria → setor padrão → nada | [GERAL] |
| Pizza de sabores segue a rota do produto/1º sabor | [PIZZARIA] |
| Explosão por unidade conforme o setor | [CONFIG] |
| Snapshot do que produzir no momento da entrada na fila; alteração depois do início gera alerta com "Ciente", nunca troca silenciosa | [GERAL] |
| Transições com `lockForUpdate` + versão enviada pelo tablet | [GERAL] |
| Desfazer em até N segundos; recall até expedir | [CONFIG] |
| Refazer cria nova linha urgente com motivo | [GERAL] |
| Cores por tempo (alerta/alvo/atraso × multiplicador); "nada se move sob o dedo" | [GERAL] |
| Pedido nunca retrocede por causa da cozinha; prevenção de laço observer ↔ produção | [GERAL] |
| 1º item iniciado → pedido em preparo; todos prontos → pronto; despacho → próximo status por tipo | [GERAL] |
| Checklist de abertura bloqueando produção (opcional) | [EMPÓRIO] / fora |
| Ficha técnica com frações por sabor | [PIZZARIA] / fora da 1ª entrega |

## 4. Proposta incremental (P4)

| Etapa | Entrega | Depende de |
|---|---|---|
| 4.1 | Aviso de pronto ao garçom + selo de mesa no kanban | P3 (entra na Fase 3.2) |
| 4.2 | Evoluir `ProductionLine` → setor (cor, ordem, tempo alvo/alerta, dispensa produção no produto) | — |
| 4.3 | `production_items` + `production_events` (snapshot, status por item, sem etapas múltiplas) gerados após o commit da transição para `Open` | 4.2 |
| 4.4 | Página **Estação**, copiada de `cozinha/pages/estacao.blade.php` (§4.1): Fila → Em preparo → Prontos, filtro por setor, polling com versão | 4.3 |
| 4.5 | Sincronização produção → pedido (preparando/pronto) sem retroceder | 4.3 |
| 4.6 | **Expedição**, copiada de `cozinha/pages/expedicao.blade.php` (§4.1), + refazer com motivo | 4.5 |
| 4.7 | Tablet pareado por QR (token por tenant) + operador | 4.4 |
| 4.8 | Etapas múltiplas, disparo por tempo, all-day count, relatório de produção | 4.6 |
| Futuro | Ficha técnica viva (depende de insumos/estoque avançado) | Estoque |

## 4.1 Cópia do front do Pizzaria-App

Revisado em 2026-11-06: as telas da cozinha são copiadas conforme a §2.4.9 do documento principal, traduzindo apenas os vínculos com o back-end.

| Tela | Arquivos copiados (origem em `resources/views/`) | Etapa |
|---|---|---|
| Estação | `filament/cozinha/pages/estacao.blade.php`, `pages/estacao/card.blade.php`, `partials/operador.blade.php`, `components/cozinha/*` | 4.4 |
| Expedição | `filament/cozinha/pages/expedicao.blade.php`, `filament/cozinha/producao-pedido-modal.blade.php` | 4.6 |
| Pareamento e operador | `filament/cozinha/pareamento-qr.blade.php`, `filament/cozinha/partials/operador.blade.php` | 4.7 |
| Setores, rotas e dispositivos | Campos, colunas e ordem de `app/Filament/Resources/{SetoresProducao,DispositivosCozinha}` | 4.2 e 4.7 |

Também são copiados o tema (`resources/css/filament/cozinha/theme.css`) e a configuração de `CozinhaPanelProvider`: `darkMode(true, isForced: true)`, `spa()`, `topNavigation()` e largura total. Blocos de ficha técnica e checklist ficam fora da cópia até essas funções existirem. **Aceite visual:** `diff` contra a origem e capturas lado a lado no tamanho de tablet.

## 5. Multi-tenant

- `production_items`/`production_events` com `tenant_id` (volume alto, consultas por setor sem join).
- Dispositivo pareado: token por tenant; middleware equivalente a `IdentificaDispositivoCozinha` resolve tenant **e** setor; reserved slug `cozinha` se o painel for `/cozinha/{slug}`.
- Contador de versão em cache com chave `tenant:{id}:kitchen:sector:{id}`.
- Comandos (`liberar-itens`, `alertar-atrasos`) tenant-aware (MT-01).

## 6. Critérios de aceite (quando iniciar a P4)

1. Pedido confirmado gera itens de produção uma única vez (reprocessar não duplica).
2. Dois tablets tocando o mesmo item → só uma transição vence.
3. Pedido nunca retrocede de status por ação da cozinha.
4. Alteração após início exige "Ciente".
5. Tenant sem a feature continua só com o kanban atual, sem regressão.
6. Estação e Expedição passam no aceite visual da §4.1.
