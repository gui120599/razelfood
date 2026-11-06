# Prioridade 3 — Painel do Garçom / Salão no RazelFood

> Parte do plano [migracao-funcionalidades-pizzaria-app-para-razelfood.md](migracao-funcionalidades-pizzaria-app-para-razelfood.md).
> **Data:** 2026-11-04 · **Status:** planejamento, nada implementado.
> **Legenda:** **[FATO]** comprovado no código local · **[INFERÊNCIA]** dedução · **[DECISÃO]** depende de aprovação.

---

## 1. Como funciona no Pizzaria-App

### 1.1 Painel e acesso [FATO]

- Painel Filament `garcom` em `/garcom` (`GarcomPanelProvider`): `spa()`, `topNavigation()`, largura total, tema próprio, `databaseNotifications()` com polling 30 s (sino "pedido pronto"), PWA (manifest + service worker) e Web Push opcionais.
- **Login** (`LoginGarcom`): **PIN com escolha rápida do usuário** (aparelho compartilhado do salão) ou e-mail/senha (aparelho próprio). PIN com bloqueio após 5 erros por 300 s (`PinService`, `RateLimiter`).
- Papéis: `GARCOM_PANEL_ROLES = Admin, Gerente, Atendente, Garcom`; `Cozinha`/`Caixa` entram só para checklists; colaborador sem papel do salão entra só para ponto. Home = mapa de mesas para quem atende salão.
- Páginas: `MapaMesas`, `AtenderMesa` (749 linhas), `AtenderRetirada`, + ponto/checklists/notificações/plantão (fora de escopo).

### 1.2 Entidades [FATO]

| Model | Papel |
|---|---|
| `Mesa` | `mesa_nome`, `mesa_tipo` (MESA/COMANDA — mesmo ciclo, só muda a apresentação), `mesa_status` (LIBERADA/OCUPADA/INATIVA), `mesa_sessao_atual_id` |
| `SessaoMesa` | A conta: status (ABERTA/FECHADA/…), pessoas, taxa de serviço %, conta solicitada em, versão (lock otimista), cliente principal |
| `SessaoMesaCliente` | Pessoas da mesa (para conta por pessoa) |
| `Pedido` (`pedido_sessao_mesa_id`, `pedido_usuario_garcom_id`, origem `MESA`/`GARCOM`) | Cada **rodada** |
| `ItensPedido` (`item_pedido_cliente_id`, `item_pedido_venda_id`) | Item com pessoa e vínculo com a venda |
| `AutorizacaoGerente` | Trilha de ações autorizadas por PIN/permissão (morph auditável) |
| `MesaChamado`, `MesaParticipante` | QR da mesa (fora da v1) |

### 1.3 Mapa de mesas [FATO]

`MapaMesasService` monta o mapa em poucas queries (mesas + sessão atual + agregado de status das rodadas + pendências do QR). Status **derivado** por prioridade (`StatusMapaMesaEnum`, o primeiro que se aplica vence):
`APROVAR_PEDIDO` (QR) › `CHAMOU_GARCOM` (QR) › `CONTA_SOLICITADA` › `PRONTO` › `EM_PREPARO` › `AGUARDANDO_PEDIDO` › `ATENDIDA` › `LIVRE` › `INATIVA`. Alerta de mesa parada após 40 min sem rodada. Polling 10 s.

### 1.4 Atender mesa — operações [FATO, `AtendimentoMesaService` + `AtenderMesa`]

| Operação | Regra |
|---|---|
| Abrir mesa | `SessaoMesaService::abrir` trava a mesa; ocupada/inativa → exceção; taxa de serviço copiada da config |
| Rascunho da rodada | 1 pedido INICIADO por garçom × sessão (exclui pedido do QR); itens gravados direto (sobrevive a queda de rede) |
| Incluir produtos | `PedidoProdutoSelector` (sabores, adicionais, perguntas, observação); produto pode ser oculto para o garçom (`produto_cardapio_garcom`); item pode ser atribuído a uma pessoa |
| Enviar à cozinha | `enviarRodada`: lock → se já não INICIADO, no-op (reenvio) → sem itens = erro → grava totais → INICIADO→ABERTO |
| Acompanhar | Lista de rodadas com status; polling 5 s; sino quando a cozinha marca pronto |
| Entregar | Rodada PRONTO → ENTREGUE (`marcarEntregue`) |
| Editar rodada enviada | `editarPedido` reabre a edição de uma rodada (regras de status do painel de pedidos) |
| Cancelar item | Autorização (permissão `cancelar_item:pedido` ou PIN de gerente) + motivo; proibido se item já em venda, conta fechada ou pedido encerrado; estorna promoções; último item cancela a rodada |
| Pessoas | Alterar nº de pessoas (lock otimista por versão); adicionar/remover pessoa da conta |
| Taxa de serviço | Remover exige autorização; restaurar não |
| Transferir mesa | Autorização; destino precisa estar LIBERADA (lock) |
| Mover rodadas entre contas | `adicionarPedidoAction`/`removerPedidoAction` |
| Solicitar conta | Marca/desmarca `conta_solicitada_em` |
| Pré-conta | `ContaMesa` (itens ativos de rodadas enviadas e não pagas + taxa), por pessoa com taxa proporcional, PDF |
| Fechar mesa | Só o garçom da mesa ou gerente (`Gate update`); rascunho com itens bloqueia; sem rodadas → sessão cancelada; senão FECHADA e **mesa liberada**; conta fica pendente no caixa |
| Cobrar na maquininha | `MesaStoneCobranca` (Stone, origem `sessao_mesa`) |

### 1.5 Retirada pelo garçom [FATO]

`RetiradaService`: mesmo desenho de rascunho → enviar, sem sessão de mesa e sem taxa de serviço; opção de entrega configurável (`opcao_entrega_retirada_id`, ou a primeira que não exige endereço).

### 1.6 Comunicação com a cozinha [FATO]

- Garçom → cozinha: pedido ABERTO (rodada) entra no painel de pedidos e, se o módulo cozinha estiver ativo, gera itens de produção.
- Cozinha → garçom: produção pronta → notificação de banco ("pedido pronto") + push `PEDIDO_PRONTO` aos garçons em turno.

---

## 2. Situação no RazelFood

| Necessidade | Existe? |
|---|---|
| Mesas/comandas, sessão de mesa | ❌ (só `OrderOrigin::Table` e `OrderFulfillmentType::DineIn`) |
| Seletor de produtos com sabores/adicionais/brindes | Regras ✅ em `ProductCatalogSelector`, `FlavorPickerModal` e `AddonPickerModal`; o front será a cópia de `pedido-produto-selector` (Fase 1.S), pré-requisito desta fase |
| Criação/edição de pedido no servidor com preço/estoque/promoção | ✅ `CreateOrderFromCart`, `UpdateOrderFromCart`, `CartStockAndPromotionLedger` |
| Transições de status | ✅ `AdvanceOrderStatus`, `CancelOrder`, `OrderStatusActions` |
| Kanban de pedidos | ✅ `Kitchen` (mostra rodada automaticamente se for um `Order`) |
| Comanda imprimível | ✅ `orders/ticket.blade.php` |
| PIN de usuário / autorização de gerente | ❌ |
| Notificações de banco | Parcial: tabela `notifications` existe e `databaseNotifications()` está ligado **só no painel central** — habilitar no painel `garcom` |
| Venda/pagamento | ❌ → Fase 1.0 |

---

## 3. Requisitos funcionais (v1)

| # | Requisito | Origem |
|---|---|---|
| RF-G01 | Cadastro de mesas/comandas por tenant (nome/número, tipo, ativa, ordem, área opcional) | `Mesa` |
| RF-G02 | Mapa de mesas mobile com status derivado (§1.3, sem estados do QR) e alerta de mesa parada | `MapaMesasService` |
| RF-G03 | Abrir mesa (pessoas, cliente opcional) com lock | `SessaoMesaService::abrir` |
| RF-G04 | Montar rodada (rascunho persistido) e enviar à cozinha idempotente | `AtendimentoMesaService` |
| RF-G05 | Acompanhar status das rodadas e marcar entregue | idem |
| RF-G06 | Cancelar item com motivo e autorização | idem |
| RF-G07 | Taxa de serviço por tenant; remover com autorização; restaurar | idem |
| RF-G08 | Transferir mesa com autorização | idem |
| RF-G09 | Pessoas da mesa e conta por pessoa | `ContaMesa` |
| RF-G10 | Solicitar conta; pré-conta imprimível, com o layout copiado do Pizzaria-App | `PDFController::sessaoMesaPDF` |
| RF-G11 | Fechar mesa (libera a mesa; conta vai para "Contas a fechar" no fechamento de conta/Venda) | `fecharSessao` |
| RF-G12 | Retirada lançada pelo garçom | `RetiradaService` |
| RF-G13 | Aviso de "rodada pronta" ao garçom (notificação no painel + destaque no mapa) | notificação de banco |
| RF-G14 | Cobrar a conta na maquininha (se P2 ativa) | `MesaStoneCobranca` |
| RF-G15 | Histórico do atendimento (rodadas, quem lançou, autorizações) | `AutorizacaoGerente` |

**Fora da v1:** QR/autoatendimento, push PWA, plantão de freelancers, ponto, checklists, cortesia, divisão de item, mover rodadas entre contas, perguntas do item.

---

## 4. Arquitetura proposta [DECISÃO D-06]

### 4.1 Painel separado

Novo `WaiterPanelProvider` (`id: garcom`, `path: garcom`) com **tenancy nativa do Filament** (`->tenant(Tenant::class, slugAttribute: 'slug')`) e o mesmo `ApplyTenantScopes` persistente do painel do tenant → `/garcom/{slug}`. Motivos:

- Layout mobile próprio (top navigation, sem sidebar, alvos de toque grandes) sem afetar o painel administrativo.
- Navegação restrita às telas do salão (sem `discoverPages`).
- Mesmo isolamento já provado no painel do tenant.

Alternativa: páginas dentro de `/painel/{slug}` com layout simplificado — menos código, mas mistura o menu administrativo no celular do garçom. **Recomendado: painel separado.**

Pré-requisitos: `garcom` em `config/tenancy.reserved_slugs`; `User::canAccessPanel('garcom')` por papel; papel novo **Garçom** em `SeedDefaultTenantRoles`.

### 4.2 Login

v1: **e-mail/senha** (padrão Filament) — o isolamento já está resolvido (e-mail globalmente único, 1 usuário = 1 tenant).
v2: PIN rápido em **dispositivo pareado** (token de dispositivo por tenant, como `DispositivoCozinha` no Pizzaria-App) — nunca listar usuários numa URL pública (MT-10). PIN também é usado na v1 **para autorização de gerente** (não para login).

### 4.3 Dados

| Tabela | Campos | Notas |
|---|---|---|
| `dining_tables` | `tenant_id`, `name`, `type` (table/tab), `status` (free/occupied/inactive), `current_session_id`, `sort_order`, `area` | unique `(tenant_id, name)` |
| `table_sessions` | `tenant_id`, `dining_table_id`, `opened_by_user_id`, `waiter_user_id`, `client_id`, `people_count`, `service_fee_percent`, `bill_requested_at`, `status` (open/closed/paid/cancelled), `opened_at`, `closed_at`, `version` | |
| `table_session_people` | `table_session_id`, `client_id`/`label` | conta por pessoa |
| `orders` (+) | `table_session_id`, `waiter_user_id` | rodada = `Order` com `origin = table`, `fulfillment = dine_in` |
| `order_items` (+) | `table_session_person_id`, `sale_id` (Fase 1.0) | |
| `users` (+) | `pin_hash` | PIN com `Hash`, rate limit por usuário |
| `manager_authorizations` | `tenant_id`, `action` (enum), `requested_by`, `authorized_by`, `auditable_type/id`, `reason`, `data` (json) | append-only |
| `tenants` (+) ou `tenant_floor_settings` | `service_fee_enabled`, `service_fee_percent`, `idle_table_alert_minutes`, `waiter_pickup_delivery_option_id` | substitui `config('pizzaria.salao')` |
| `products` (+) | `show_to_waiter` | `produto_cardapio_garcom` |

> Nota: `orders.client_id` já é nullable (migration `2026_08_22_033455`), então rodada sem cliente é suportada.

### 4.4 Ações (reuso, sem duplicar regra)

| Ação nova | Reusa |
|---|---|
| `OpenTableSession` | lock da mesa |
| `GetOrCreateRoundDraft` | `CreateOrderFromCart` (adaptado para origem mesa e sem checkout público) |
| `SendRoundToKitchen` | `AdvanceOrderStatus` Started→Open |
| `MarkRoundDelivered` | Ready→Finished? **Atenção:** no RazelFood, consumo local de `Ready` vai direto para `Finished` (`next(false)`). Para rodada de mesa, "entregue na mesa" deve ser um passo explícito: propor usar `Delivered` também para `dine_in` (`Ready → Delivered` para mesa) e `Finished` só quando a conta é paga — **[DECISÃO]**, alinhado a RN-PED-03 |
| `CancelOrderItem` | `UpdateOrderFromCart` (recalcula totais/estoque/promo) + `AuthorizeManagerAction` |
| `AuthorizeManagerAction` | permissões spatie com teams + PIN |
| `RemoveServiceFee` / `RestoreServiceFee` / `ChangePeopleCount` | lock otimista por `version` |
| `TransferTableSession` | lock da mesa destino |
| `CloseTableSession` | Gate + regra de rascunho |
| `BuildTableBill` | equivale a `ContaMesa` (fonte única para pré-conta, Stone e Venda) |

O painel do garçom **não calcula preço, estoque nem status** — só chama as Actions.

---

## 5. Cópia do front do Pizzaria-App

> Revisado em 2026-11-06: as telas **não são redesenhadas**. Os arquivos abaixo são copiados do Pizzaria-App conforme a §2.4.9 do documento principal, traduzindo apenas os vínculos com o back-end.

| Tela | Arquivos copiados (origem em `resources/views/`) | Estrutura preservada |
|---|---|---|
| Mapa | `filament/garcom/pages/mapa-mesas.blade.php`, `partials/{sem-conexao,alerta-pronto}` | Abas Mesas, Comandas e Viagem; grade por área; legenda |
| Atender mesa | `filament/garcom/pages/atender-mesa.blade.php`, `partials/{pessoas-mesa,carrinho-lateral,mesa-rodadas,mesa-conta}` | Celular: Pedir · Rodadas · Conta. Desktop: grid 12 colunas (7 + 5), painel com Rodada atual · Rodadas · Conta |
| Seletor de produtos | `livewire/pedido-produto-selector.blade.php`, `resources/js/pizza-svg-painel.js` | Mesmo seletor do Atender Pedido, copiado na Fase 1.S |
| Retirada | `filament/garcom/pages/atender-retirada.blade.php`, `livewire/{cliente-picker,entrega-pagamento-picker}`, `components/pedido/*` | Mesmo fluxo |
| Pré-conta | View usada por `PDFController::sessaoMesaPDF` | Mesmo layout |

Também são copiados o tema (`resources/css/filament/garcom/theme.css`) e a configuração visual de `GarcomPanelProvider`: `spa()`, `topNavigation()` e largura total. Mantidos: polling de 10 s no mapa e 5 s na mesa, rascunho salvo no servidor e confirmação nas ações destrutivas.

Removidos da cópia até a fase correspondente: `mesa-qr`, `convite-push`, ponto, checklists e plantão. O `login-pin` só entra com dispositivo pareado (§4.2).

**Aceite visual:** `diff` contra a origem com apenas vínculos traduzidos e exceções, e capturas lado a lado em desktop e celular.

## 6. Integração com os demais módulos

| Módulo | Relação | Não duplicar |
|---|---|---|
| Cardápio web | Mesmo catálogo/preço; `show_to_waiter` filtra | Preço sempre por `ResolvePriceForCartLine` |
| Painel administrativo | Cadastro de mesas, configurações do salão, histórico de sessões e autorizações | — |
| Painel da cozinha (`Kitchen` atual) | Rodada aparece com selo "Mesa X — rodada N"; "Pronto" notifica o garçom | Transições continuam em `AdvanceOrderStatus` |
| Fechamento de conta (Venda, Fase 1.0) | Mesa fechada vira item na lista "Contas a fechar"; a Venda agrega as rodadas | Totais da conta via `BuildTableBill` |
| Stone (P2) | "Cobrar na maquininha" na aba Conta (origem `table_session`) | Valor devido via `ResolveAmountDue` |
| NFE.io (P1) | Nota emitida pela Venda da mesa; taxa de serviço rateada (RN-FIS-07) | Garçom não emite nota |
| Caixa (futuro) | Venda nasce sem sessão de caixa | — |

---

## 7. Critérios de aceite (P3)

1. Dois garçons abrindo a mesma mesa ao mesmo tempo → uma sessão só.
2. Reenvio da rodada após falha de rede não duplica pedido nem estoque.
3. Rodada aparece na Central de Pedidos com origem Mesa; "Pronto" gera aviso ao garçom em ≤ 15 s.
4. Cancelar item sem permissão exige PIN válido de quem tem a permissão; PIN bloqueia após 5 erros; autorização gravada com motivo.
5. Fechar mesa com rascunho não enviado é recusado; sem rodadas cancela a sessão; com rodadas libera a mesa e a conta aparece em "Contas a fechar".
6. Conta por pessoa soma exatamente o total (taxa proporcional em centavos).
7. Garçom de um tenant não acessa `/garcom/{outro-slug}`; mesa/sessão/rodada de outro tenant retorna 404.
8. Testes de concorrência por design (lock) + testes Livewire das páginas + teste de navegação mobile em navegador real (ver memória de Chromium sem sudo).
9. Feature desligada (`salao`/`pdv`) → painel inacessível para o tenant.
