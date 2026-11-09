# Painel de Pedidos — Pizzaria-App → RazelFood

> **Tipo:** levantamento + plano de implementação (Fase 1.S.2, 1ª parte).
> **Data:** 2026-11-09.
> **Status:** aprovado em 2026-11-09 (todas as recomendações da §12, D-PP-01 a D-PP-08). **1.S.2a** em execução; 1.S.2b e 1.S.2c pendentes.
> **Fase do plano mestre:** 1.S (cópia das telas de pedido). Parte do [plano mestre](migracao-funcionalidades-pizzaria-app-para-razelfood.md); o KDS fica em [painel-cozinha-razelfood.md](painel-cozinha-razelfood.md) (P4).
> **Legenda:** **[FATO]** comprovado no código · **[INFERÊNCIA]** dedução · **[HIPÓTESE]** a confirmar · **[DECISÃO]** depende de aprovação.
> **Origem:** `/home/gui120599/Pizzaria-App` (branch `prod-new`, somente leitura). **Destino:** `Kitchen` ("Central de Pedidos", `/painel/{slug}/cozinha`).

---

## 1. Objetivo

Fazer a tela de kanban de pedidos do RazelFood ficar **igual** ao Painel de Pedidos do Pizzaria-App (§2.4.9: os dois são o mesmo sistema; o front é copiado e só os vínculos são traduzidos). Hoje as duas telas têm o mesmo esqueleto (filtros, colunas por status, cards, modal de detalhes), mas o RazelFood tem um card mais pobre, não tem o corte por turno, a escolha de colunas, o som, a impressão ao aceitar nem o menu de ações completo. Dor operacional visível na captura de 2026-11-08: o board do RazelFood mostra pedidos de 44 h atrás como "ATRASADO" misturados com a fila do turno, enquanto o Pizzaria-App os recolhe atrás do aviso "N pedidos de turnos anteriores (mostrar)".

Fora desta parte da 1.S.2: conferência de Vendas e telas fiscais, e alinhamento do menu (D-09). Cada um terá o seu levantamento.

## 2. Comportamento na origem

**Página** `app/Filament/Pages/PainelPedidos.php` (874 linhas, [FATO]):

1. **Acesso:** `canAccess()` exige `view_any:pedido` (l.150-153). Slug `pedidos/painel`, grupo "Comercial", largura total (l.64-78).
2. **Filtros** (l.112-148): `filtroRapido` (`todos|delivery|retirada|mesa|novos|preparando|prontos|em_entrega|finalizados`), `busca`, `entregadorId`, `periodoDe/Ate`, `somenteAtrasados`, `mostrarCancelados` guardados na sessão (`#[Session]`); `linhaProducaoId` só por URL `?linha=` (fixa um tablet numa estação), com cópia na sessão (l.166-178).
3. **Colunas ativas** (`colunas()`, l.411-453): uma query só; um filtro rápido de etapa única esvazia as demais colunas; `finalizados` esvazia todas; descarta pedido sem item; ordena por id crescente (fila). **Só entram pedidos do turno** (`pedido_datahora_abertura >= JanelaOperacional::atual()[0]`), a menos que haja período informado ou o operador mostre "fora do turno" (l.435-443).
4. **Coluna final** (`entregues()`, l.466-480): ENTREGUE + FINALIZADO juntos, janela do turno escolhido num seletor de data ("Turno de"), id decrescente, limite 50 e botão "Carregar mais entregues" (200) (l.868-873; `config/pizzaria.php`, bloco `pedidos`).
5. **Cancelados** (l.489-505): faixa à parte, escondida por padrão, 20 mais recentes do turno, com motivo e quem cancelou.
6. **Fora do turno** (l.513-531): só a contagem por status; o botão âmbar "N pedidos de turnos anteriores (mostrar/ocultar)" liga a listagem (view l.116-134).
7. **Colunas visíveis** (l.558-583): seletor "Colunas" grava `colunas_ocultas` nas preferências do usuário (vale em qualquer aparelho); nunca deixa ocultar todas.
8. **Ação primária por pedido** (l.616-641): Novos → "Confirmar"; Aceitos → "Iniciar preparo"; Preparando → "Marcar pronto"; Prontos → "Despachar" (com entregador) / "Saída para entrega" / "Finalizar"; Em entrega → "Confirmar entrega". Cancelar é "Rejeitar" até ABERTO e "Cancelar" depois (`coluna.blade.php`).
9. **Tempo real** (l.285-312, 359-379): `wire:poll.10s="atualizar"`; uma "assinatura" (contagem + `MAX(updated_at)` por status) decide se renderiza; sem mudança, `skipRender()`. Se a fila de entrada cresce, dispara `novo-pedido` (som). O "Atualizado às" vem do hook de commit do Livewire (view l.236-262).
10. **Som e impressão** (view l.234-353): botão "Ativar som" (beep sintetizado, gesto que destrava o áudio, preferência no `localStorage`); "Aceitar" imprime a comanda num iframe oculto (l.200-207); "Imprimir rodadas de mesa" imprime sozinho as rodadas do salão (l.319-343).
11. **Cabeçalho:** ações "PDF do dia" e "PDF de entregas" (l.260-279).

**Views** (`resources/views/filament/pages/painel-pedidos*`): `painel-pedidos.blade.php` (354), `coluna.blade.php` (100), `card.blade.php` (312), `cancelado-card.blade.php` (30), `detalhes-modal.blade.php` (200), `link-entrega-modal.blade.php` (29).

**Card** (`card.blade.php`, [FATO]): borda esquerda por urgência (l.38); `#id`, mesa, badge do tipo de atendimento, nome da opção de entrega, cliente e celular (l.47-70); badge de tempo no status + hora de abertura (l.74-95); endereço quando exige entrega (l.99-104); entregador (l.106-111); **todos** os itens com quantidade, nome/sabores, adicionais, `OBS:` e valor (l.117-158); "Já pago · venda #" (l.160-165); rodapé com botão ⋮ (Ver detalhes, Imprimir pedido, Copiar link de acompanhamento, Abrir no atendimento, Link de entrega, Trocar entregador, Rejeitar/Cancelar) e botão primário de 44 px (l.173-311). Itens de produção (chips por item, "Ver produção", "Priorizar", "Liberar") vêm do módulo de cozinha (l.31-34, 113-114, 132-138, 225-256).

**Semáforo de SLA** (`UrgenciaPedidoEnum`): atenção/atrasado por **status**, limites em `config('pizzaria.pedidos.sla')` (INICIADO 3/8, ABERTO 5/10, PREPARANDO 20/35, PRONTO 10/20, EM TRANSPORTE 30/45 min), contando desde a entrada no status atual.

**Testes da origem** que descrevem o comportamento esperado: `tests/Feature/PainelPedidos{,Filtros,Acoes,QueryCount}Test.php` (44 casos, entre eles "pedido de turno anterior sai do board e entra na contagem", "ocultar coluna persiste na preferência do usuário", "poll sem mudança mantém a assinatura", "numero de queries não cresce com a quantidade de pedidos", "aceitar manda a comanda para a impressora").

## 3. Regras de negócio

| ID | Regra | Motivo operacional | Onde (Pizzaria-App) | Classe | Como fica no RazelFood | Riscos |
|---|---|---|---|---|---|---|
| RN-PP-01 | Colunas ativas mostram só o turno atual; o resto vira contador "de turnos anteriores" com opção de mostrar | Pedido esquecido de ontem não polui a fila de hoje | `PainelPedidos.php:435-443, 513-531` | [GERAL] | Reaproveitar `ResolveActiveShiftStart` (já usado na coluna Finalizados). **Muda comportamento atual** → D-PP-01 | Tenant sem horário cadastrado: o corte vira "início do dia" e esconde de madrugada o pedido da noite anterior |
| RN-PP-02 | Coluna final = ENTREGUE + FINALIZADO, escolhendo o turno por data, com "carregar mais" | Conferência do que saiu no turno | `PainelPedidos.php:466-480, 868-873` | [GERAL] | Adaptar a coluna "Finalizados" (hoje 20 fixos do turno atual + link do histórico) | Limites 50/200 viram constantes do código (não por tenant) |
| RN-PP-03 | Pedido sem item não gera card | Evita card vazio | `PainelPedidos.php:850-855` | [GERAL] | [INFERÊNCIA] não se aplica: o RazelFood não cria pedido sem item. Cobrir com teste | — |
| RN-PP-04 | "Novos" só mostra INICIADO vindo do cardápio; rascunho de outras origens fica fora | No Pizzaria-App o AtenderPedido cria INICIADO como rascunho | `PainelPedidos.php:430-434, 387-396` | [EMPÓRIO] (detalhe do fluxo deles) | **Não copiar.** No RazelFood todo pedido nasce `Started` já completo (`CreateOrderFromCart.php:160`); não há rascunho de pedido | — |
| RN-PP-05 | Ação primária decidida por pedido (despachar × finalizar), não por coluna | Prontos mistura delivery e balcão | `PainelPedidos.php:616-641` | [GERAL] | **Já existe** (`Kitchen::primaryActionName/Label`, l.313-344); só ajustar o rótulo de Novos (D-PP-04) | — |
| RN-PP-06 | Estágio "Em entrega" e atribuição de entregador são opcionais | Operações sem motoboy | `PainelPedidos.php:585-598, 695-698` (config global) | [CONFIG] | **Já é configurável por tenant** (`uses_in_transit_stage`, `assigns_delivery_couriers`; `Kitchen::boardColumns/showsDeliveryPersonnelFilter`) | — |
| RN-PP-07 | Limiares de atraso por status | Preparar leva mais que aceitar | `UrgenciaPedidoEnum`, `config/pizzaria.php` (bloco `sla`) | [CONFIG] | RazelFood tem **um par de limiares por tenant** (`order_attention_after_minutes/late`, em Configurações de Pedidos). Manter; por status fica como evolução (D-PP-05) | — |
| RN-PP-08 | Colunas visíveis por usuário (≥ 1 sempre visível) | Tablet da cozinha só com 3 colunas | `PainelPedidos.php:558-583` | [GERAL] | Adaptar: gravar em `user_preferences` (`UserPreference`, mesmo mecanismo de `PersistsFilterPreferences`) no lugar de `users.preferencias` | Chave nova; o isolamento por tenant já vem do `TenantScopedModel` |
| RN-PP-09 | Alerta sonoro quando a fila de entrada cresce | Cozinha não olha a tela o tempo todo | `PainelPedidos.php:290-312`, view l.234-350 | [GERAL] | Copiar. Preferência fica no navegador (`localStorage`), não no banco | Autoplay bloqueado: o botão é o gesto |
| RN-PP-10 | Aceitar imprime a comanda sozinho | Impressora na cozinha | `PainelPedidos.php:200-207` | [CONFIG] (depende de ter impressora) | Adaptar com a rota `order.ticket`; ligar por aparelho (D-PP-03) | Bloqueador de pop-up: imprimir em iframe oculto |
| RN-PP-11 | Poll com assinatura e `skipRender()` | Hospedagem fraca; muitos tablets | `PainelPedidos.php:290-312, 359-379` | [GERAL] | Copiar, sem o contador do módulo de cozinha | Contagem + `MAX(updated_at)` perde mudanças feitas por query builder sem `updated_at` (a origem já soma a contagem para isso) |
| RN-PP-12 | Card mostra todos os itens, adicionais, observação, endereço, entregador e "Já pago" | A cozinha confere tudo no card, sem abrir o pedido | `card.blade.php:99-165` | [GERAL] | Copiar; traduzir campos (itens: `quantity`, `note`, `addons`, `gifts`; "Já pago" = `sale_id` preenchido, ver §5) | Brindes (RN-53) existem só no RazelFood: manter a linha "🎁 … grátis" que o card atual já tem |
| RN-PP-13 | Menu ⋮ expõe link de entrega, trocar entregador, copiar link de acompanhamento, abrir no atendimento | Operação toda na mesma tela | `card.blade.php:173-291` | [GERAL] | Copiar; as ações `deliveryLink` e `reassignDelivery` **já existem** em `Kitchen` mas **não têm botão** no card do kanban (só na página `ViewOrder`) | Permissões: `manage_order_status`; link de acompanhamento usa `public_token` |
| RN-PP-14 | Pizza de vários sabores aparece com uma linha por sabor ("MEIA X") | Cozinha lê a fração | `x-item-nome` (`components/item-nome.blade.php`) | [PIZZARIA] | Adaptar: o RazelFood já tem a fração (`FlavorFraction`, usada no carrinho e na NFC-e). Manter `displayName` (contrato dos testes) e acrescentar as linhas | `KitchenItemDisplayNameTest` fixa o `displayName` com " / " |
| RN-PP-15 | PDFs "do dia" e "de entregas" no cabeçalho | Fechamento do turno | `PainelPedidos.php:260-279` | [GERAL] | Adaptar para as impressões que o RazelFood já tem (`reports.orders.print`, `reports.deliveries.print`) (D-PP-06) | Exigem feature `RELATORIOS` e `View:Reports` |

## 4. Front-end

**Arquivos da origem a copiar** → destino:

| Origem (`resources/views/filament/pages/`) | Destino |
|---|---|
| `painel-pedidos.blade.php` | `resources/views/filament/tenant/pages/kitchen.blade.php` (reescrita sobre o mesmo contrato da página) |
| `painel-pedidos/coluna.blade.php` | `resources/views/filament/tenant/pages/kitchen/column.blade.php` (novo) |
| `painel-pedidos/card.blade.php` | `resources/views/components/orders/order-card.blade.php` (mantém o nome e as props) |
| `painel-pedidos/cancelado-card.blade.php` | `resources/views/filament/tenant/pages/kitchen/cancelled-card.blade.php` (novo) |
| `painel-pedidos/detalhes-modal.blade.php` | `resources/views/filament/orders/order-details-modal.blade.php` (já é quase idêntico; ver §5) |
| `painel-pedidos/link-entrega-modal.blade.php` | `resources/views/filament/orders/delivery-link-modal.blade.php` (já existe, com botão "Copiar") |

**Vínculos a traduzir** (e só eles): `wire:*` e propriedades (`busca`→`search`, `filtroRapido`→`quickFilter`, `entregadorId`→`deliveryUserFilter`, `linhaProducaoId`→`productionLineFilter`, `periodoDe/Ate`→`periodFrom/Until`, `somenteAtrasados`→`onlyLate`, `mostrarCancelados`→`showCancelled`, `mountAction('verDetalhes', {pedido})`→`mountAction('viewDetails', {order})`), colunas (`pedido_*`→`orders.*`), enums (`StatusPedidoEnum`→`OrderStatus`, `UrgenciaPedidoEnum`→`OrderUrgencyLevel`), `route()` e `getUrl()` sem o parâmetro `tenant`. Nomes de ações, propriedades e computeds que os testes `Kitchen*` já usam **não mudam**.

**Exceções da §2.4.9 aplicadas** (ficam de fora da cópia): mesa, rodadas de mesa e "Imprimir rodadas de mesa" (Fase 3 do garçom); itens, chips e ações de produção — "Ver produção", "Priorizar", "Liberar produção agora", `x-pedido.producao`, `x-cozinha.status-chip` (P4); selo 🎁 de oferta de promoção adicional (`item_pedido_origem_id`; fora de escopo); CORTESIA no modal (fora de escopo); o parâmetro `?linha=` e a preferência continuam como estão.

**Tailwind:** a origem usa Tailwind 3.1 e o RazelFood v4. Conferir na cópia os utilitários renomeados (`shadow-sm`→`shadow-xs`, `ring`→`ring-3`, `flex-grow`→`grow`) e `bg-opacity-*`. A origem usa as cores `primary-*`, `danger-*`, `warning-*`, `info-*` e `success-*` do Filament, que o painel Tenant já registra. Classes novas precisam estar cobertas pelo `@source` do tema (`.ai/rules/filament.md`).

**Propostas de melhoria** (cada uma **aguarda aprovação**; no Pizzaria-App quem aplica é você):
1. **Poll só com a aba visível** (`wire:poll.10s.visible`): reduz requisições na HostGator quando o tablet fica com a aba em segundo plano. Não existe na origem.
2. **Urgência além da cor:** hoje atenção e atraso se distinguem só pela cor da borda e do chip. O texto "Atrasado" no `title` já existe; falta torná-lo visível para quem não distingue cores.

Sem outras mudanças de experiência.

## 5. Situação atual do RazelFood e diferenças

Tabela origem × destino × diferença × decisão. Fontes do destino: `app/Filament/Tenant/Pages/Kitchen.php`, `resources/views/filament/tenant/pages/kitchen.blade.php`, `resources/views/components/orders/order-card.blade.php`, `app/Filament/Tenant/Support/OrderStatusActions.php`.

| # | Aspecto | Origem | Destino hoje | Decisão |
|---|---|---|---|---|
| 1 | Título e menu | "Painel de Pedidos", grupo Comercial, slug `pedidos/painel` | "Central de Pedidos", grupo Pedidos, slug `cozinha` (mantido de propósito, `Kitchen.php:46-56`) | **Adaptar** só rótulo/grupo, junto do D-09 (D-PP-02). Slug, feature e permissões **não mudam** |
| 2 | Corte por turno nas colunas ativas | Sim + contador "turnos anteriores" | Não: `ordersByStatus` traz tudo em andamento (`Kitchen.php:160-183`) | **Estender** (D-PP-01) |
| 3 | Coluna final | ENTREGUE+FINALIZADO, seletor de turno, 50/200 | 20 fixos do turno atual, link "Ver histórico completo →" (`:100`, `kitchen.blade.php:174`) | **Adaptar** |
| 4 | Filtros | busca, tipo, entregador, linha, período, atrasados, cancelados | Os mesmos, com **rótulos diferentes**: "Pendentes/Em preparo/Consumo local/⚠ Atrasados" × "Novos/Preparando/Mesa/⚠ Só atrasados" (`kitchen.blade.php:2-12, 69`) | **Copiar rótulos**; chaves (`pending`, `dine_in`…) ficam |
| 5 | Período informado | Substitui a janela do turno | Filtra por `created_at`, sem janela do turno | **Adaptar** junto com a #2 |
| 6 | Colunas visíveis | Seletor "Colunas" por usuário | Não existe | **Criar** (em `user_preferences`) |
| 7 | Navegação | Chips que rolam até a coluna (abaixo de `xl`) + grid de colunas `minmax(19rem,1fr)` | Faixa fixa `w-80` e abas de coluna no celular (`kitchen.blade.php:118`) | **Copiar** |
| 8 | Cabeçalho da coluna | Cor, ícone e contador por status (`StatusPedidoEnum::cor/icone/labelColuna`) | Ponto colorido + "Rótulo · n" (`columnDotClass`) | **Copiar**; `OrderStatus::icon()` e rótulos de coluna já existem |
| 9 | Card: itens | **Todos**, com adicionais, `OBS:` e valor | **2 itens** + "+ n itens", sem observação nem valor (`order-card.blade.php:18`) | **Adaptar** (inclui `items.note`, que a query ainda não seleciona) |
| 10 | Card: contexto | opção de entrega, celular, endereço, mesa, tempo no status, "Já pago" | só cliente, tipo, total, entregador e tempo | **Estender** (sem mesa: Fase 3). "Já pago" = `sale_id` ou `finished_at` ≠ nulo — **[HIPÓTESE]**, confirmar com o dono |
| 11 | Menu ⋮ | 7 entradas (link de entrega, trocar entregador, copiar link, abrir no atendimento…) | 3 entradas; `deliveryLink` e `reassignDelivery` só existem como Actions, sem botão (**[FATO]** só aparecem em `ViewOrder.php:18-19`) | **Estender** |
| 12 | Botão primário | `h-11`, ícone seta, `wire:target` por card | `py-2`, âmbar fixo, sem spinner | **Copiar** |
| 13 | Rótulo de Novos | "Confirmar" | "Aceitar" (`Kitchen.php:325`) | **Decidir** (D-PP-04) |
| 14 | Poll | 10 s com assinatura + `skipRender()` | 20 s, renderiza sempre (`kitchen.blade.php:84`) | **Copiar** (sem o contador de cozinha) |
| 15 | Som | Sim | Não | **Criar** (`localStorage`) |
| 16 | Impressão ao aceitar | Sim | Só manual (menu "Imprimir comanda") | **Criar** como preferência do aparelho (D-PP-03) |
| 17 | PDFs no cabeçalho | 2 ações | Não | **Adaptar** (D-PP-06) |
| 18 | Modal de detalhes | cliente, mesa/entrega, itens (obs, 🎁, CORTESIA), valores, pagamentos (com "pago"), cancelamento, linha do tempo | **Praticamente igual**; falta o selo "pago" por pagamento (**[FATO]** `order_payments` não tem coluna de pago; criar uma seria mudança de dados, fora desta entrega) e o bloco "Mesa" | **Reaproveitar**; sem alteração de dados |
| 19 | Modal de link de entrega | QR + campo + WhatsApp | QR + campo + **Copiar** + WhatsApp + aviso de validade (12 h) | **Reaproveitar** (destino já é mais completo) |
| 20 | Ações de status | `PedidoStatusActions` (confirmar, aceitar, avançar, despachar, rejeitar, cancelar, trocar entregador) | `OrderStatusActions` com autorizações por permissão e transação em `AdvanceOrderStatus` | **Reaproveitar** sem mexer na regra |
| 21 | Itens de pedido na query | colunas e relações enxutas + `withCount` de produção | `filteredQuery` seleciona só `product_id, quantity, flavors, addons, gifts` (`Kitchen.php:415-432`) | **Estender** com `note`, `unit_price` e `delivery_address` |

## 6. Multi-tenant

- **Sem tabela nova.** Preferências por `user_preferences` (`UserPreference` é `TenantScopedModel`, **[FATO]**, então já isola por tenant e usuário). Chave nova: `kitchen.hidden_columns`.
- `Order`, `OrderItem`, `OrderStatusHistory` já são `TenantScopedModel`; a assinatura do poll e a contagem "de turnos anteriores" usam essas models, então herdam o `TenantScope`. **Teste obrigatório:** nada de outro tenant entra na assinatura, nos contadores nem no cancelados.
- Limiares de atraso e flags de entrega são **por tenant** (colunas em `tenants`, já existentes).
- `Kitchen` segue gateada por `FeatureKey::CENTRAL_DE_PEDIDOS` + permissão Shield. Os novos botões respeitam `manage_order_status` / `mark_order_delivered`; "Abrir no atendimento" respeita a permissão do `AttendOrder`; os PDFs, a feature `RELATORIOS` e `View:Reports`.
- Link de acompanhamento: usa `public_token` do pedido (rota pública `order.tracking`); nunca o `id`.
- Preferências do aparelho (som, impressão) ficam em `localStorage` do navegador, nunca no banco.

## 7. Estratégia de implementação

Três entregas, cada uma com testes, revisão `laravel-reviewer` e commit próprio (só com autorização):

**1.S.2a — Card, coluna e menu (sem mudar regra de dados)**
- `order-card.blade.php`: copiar o `card.blade.php` (todos os itens, endereço, opção de entrega, tempo no status, "Já pago", menu ⋮ completo, botão primário).
- `Kitchen::filteredQuery`: acrescentar `note`, `unit_price`, `delivery_address`, `sale_id` e a relação `deliveryOption`.
- Linhas de sabor "MEIA X" (`flavorLines`), mantendo `displayName`.
- Novas entradas do menu: link de entrega, trocar entregador, copiar link de acompanhamento, abrir no atendimento.
- Cabeçalho de coluna com cor e ícone; rótulos dos filtros iguais aos da origem.
- **Navegação por chips e grid responsivo** (movidos da 2b para cá: a coluna copiada já assume o grid como contêiner).
- Rótulo "Confirmar" em Novos (D-PP-04) e "Rejeitar" até "Aceitos" (a origem rejeita antes de produzir).
- Urgência além da cor: o aviso "ATRASADO · tempo" fica visível no card (D-PP-08, proposta 2).
- O card **não** mostra o total do pedido (a origem só o mostra no modal): removido para seguir a cópia.
- Arquivos: `order-card.blade.php`, `kitchen.blade.php`, `kitchen/column.blade.php` e `kitchen/cancelled-card.blade.php` (novos), `Kitchen.php`, `Order.php` (`allowsDeliveryLink`, `allowsCourierReassignment`, `trackingUrl`, compartilhados com `OrderStatusActions`), `FlavorFraction::sliceLabel`.

**1.S.2b — Turno, colunas e filtros**
- Corte por turno nas colunas ativas e botão "N pedidos de turnos anteriores" (D-PP-01).
- Coluna Finalizados com seletor de turno e "carregar mais".
- Seletor "Colunas" (preferência por usuário).
- Chips de navegação com os nomes curtos do status (Iniciado, Aberto, Preparando, Pronto, Entregue), como na origem.
- Arquivos: `Kitchen.php`, `kitchen.blade.php`, `kitchen/column.blade.php`, `ResolveActiveShiftStart::windowForOpeningDay()`.
- **Desvios deliberados da origem** (achados da revisão): o seletor "Turno de" acompanha o turno atual até o operador escolher uma data (a origem congela o dia da abertura da tela); a janela de uma data escolhida é o **dia operacional** (da primeira abertura do dia até a primeira abertura do seguinte, fim exclusivo), sem sobreposição nem lacuna; cada pedido usa um único carimbo de referência (`COALESCE(finished_at, delivered_at, opened_at)`); a coluna que o filtro rápido seleciona nunca fica escondida.

**1.S.2c — Tempo real, som, impressão e PDFs**
- Poll de 10 s com assinatura e `skipRender()`; evento `novo-pedido` e som; "Atualizado às" pelo hook do Livewire.
- Impressão da comanda ao iniciar preparo (por aparelho), em iframe oculto, reaproveitando `order.ticket`.
- Ações "PDF do dia" e "PDF de entregas" (D-PP-06).
- Arquivos: `Kitchen.php`, `kitchen.blade.php`; nenhuma migration, nenhuma permissão nova.

**Sem migrations e sem permissões novas** nas três entregas.

## 8. Dependências e exclusões

- **Influenciadores:** nenhuma dependência nesta tela (`grep` em `PainelPedidos.php`, `PedidoStatusActions.php` e nas views do painel: sem ocorrência).
- **KDS (P4):** chips por item, "Ver produção", "Priorizar", "Liberar produção agora" e o contador de produção na assinatura ficam fora; entram quando a P4 existir.
- **Salão (Fase 3):** mesa, rodadas e impressão de rodadas ficam fora.
- **Fora de escopo:** oferta de promoção adicional (🎁 de origem) e cortesia.
- **Depende de já existir:** `ResolveActiveShiftStart`, `UserPreference`, `OrderStatusActions`, rotas `order.ticket` e `order.tracking`, `OrderStatus::icon()`.

## 9. Riscos

| # | Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|---|
| 1 | Corte por turno esconde pedido legítimo de quem não cadastrou horário | M | Alto | Fallback "início do dia" já existe; contador "turnos anteriores" avisa o que foi recolhido; teste do cenário |
| 2 | Poll de 10 s com muitos tablets pesa na hospedagem compartilhada | M | M | Assinatura em 1 query agrupada, `skipRender()`, proposta `.visible`; teste de nº de queries |
| 3 | Mudar `displayName` quebra testes e outras telas | M | M | Manter `displayName`; acrescentar `flavorLines` |
| 4 | Card maior aumenta queries (N+1) | M | M | `with()` enxuto + teste de contagem de queries (padrão `PainelPedidosQueryCountTest`) |
| 5 | Impressão automática imprime pedido de outro tenant ou duplica | B | Alto | URL gerada só do pedido aceito, autorizada pelo controller `order.ticket`; só dispara após a transição bem-sucedida |
| 6 | "Já pago" sem critério definido mostra venda errada | M | M | D-PP-07; só exibir quando houver `sale_id` ou venda finalizada |
| 7 | Classes Tailwind 3→4 ou fora do `@source` quebram o visual | M | B | Checklist da skill + build + captura lado a lado |

## 10. Testes

Reaproveitar o padrão dos testes da origem e manter os `Kitchen*Test` verdes sem remover nenhum.
- **Feliz:** cada pedido na coluna do seu status; ordem por número; card com todos os itens, adicionais, observação, endereço e entregador; menu com as entradas por permissão; "Já pago".
- **Turno:** pedido de turno anterior sai do board e entra no contador; "mostrar" o traz de volta; período informado substitui a janela; coluna final respeita o turno escolhido e o limite.
- **Preferências:** ocultar coluna persiste por usuário; nunca oculta todas; chave inválida é ignorada.
- **Poll:** sem mudança mantém a assinatura; transição por query builder é detectada; nº de queries não cresce com a quantidade de pedidos.
- **Ações:** copiar link usa `public_token`; link de entrega e trocar entregador só nos status e permissões certos; imprimir só após transição que mudou o status.
- **Isolamento (dois tenants):** assinatura, contadores, cancelados, preferências e links não cruzam; `?linha=` de outro tenant não filtra nada.
- **Permissões e feature:** Entregador só vê o que `mark_order_delivered` permite; Atendente sem `manage_order_status` não vê o menu; tenant sem `CENTRAL_DE_PEDIDOS` não acessa.
- **Falha:** pedido sem cliente; sem opção de entrega; tenant sem estágio de transporte e sem entregador.

## 11. Critérios de aceite

1. As três entregas passam na suíte completa (`vendor/bin/sail artisan test --compact`), com os `Kitchen*Test` sem remoção.
2. `diff -u` de cada view copiada contra a origem mostra só vínculos traduzidos e as exceções da §4.
3. Captura lado a lado (1920 px, 1366 px, tablet e celular) sem diferença visual além das exceções.
4. Os pedidos de turnos anteriores ficam atrás do contador e voltam com "mostrar".
5. Isolamento entre dois tenants provado por teste.
6. O `git status` do Pizzaria-App ao final é igual ao do início (registrado em `/tmp/…/pz-baseline.txt` desta sessão).

## 12. Decisões pendentes

| ID | Decisão | Recomendação |
|---|---|---|
| D-PP-01 | Adotar o corte por turno nas colunas ativas, com o contador "N pedidos de turnos anteriores (mostrar)"? **Muda o comportamento atual**: hoje pedidos antigos aparecem no board. | **Sim**; é o que resolve os "ATRASADO 44 h" da captura. Reusa `ResolveActiveShiftStart`. |
| D-PP-02 | Renomear a tela para "Painel de Pedidos" e mover para o grupo Comercial? | **Sim, na entrega do menu (D-09)**, mantendo slug `cozinha`, `FeatureKey` e permissões. |
| D-PP-03 | Impressão automática da comanda ao iniciar o preparo? | **Preferência do aparelho, desligada por padrão** (nem todo tenant tem impressora). Menu "Imprimir pedido" continua. |
| D-PP-04 | Rótulo do botão de Novos: "Confirmar" (origem) ou "Aceitar" (RazelFood)? | **"Confirmar"**, por fidelidade; ajustar o teste que fixa o rótulo, se houver. |
| D-PP-05 | Limiares de atraso por status (origem) ou um par por tenant (hoje)? | **Manter um par por tenant**; por status vira evolução de configuração. |
| D-PP-06 | PDFs do cabeçalho: ligar aos relatórios de impressão existentes? | **Sim**, só quando o tenant tem `RELATORIOS` e o usuário tem `View:Reports`; sem PDF novo. |
| D-PP-07 | Critério de "Já pago · venda #": `sale_id` preenchido? | **Sim** (`orders.sale_id` marca pedido cobrado por inteiro). Confirmar. |
| D-PP-08 | Aprovar as 2 propostas de melhoria da §4 (poll `.visible` e urgência além da cor)? | **Sim às duas**; são pequenas e não mudam o desenho. |

## 13. Registro de entrega

### 1.S.2a — Card, coluna e menu (2026-11-09) · aguardando conferência visual e commit

- **Arquivos criados:** `resources/views/filament/tenant/pages/kitchen/{column,cancelled-card}.blade.php`, `tests/Feature/Orders/KitchenCardTest.php` (19 testes).
- **Arquivos modificados:** `app/Filament/Tenant/Pages/Kitchen.php`, `app/Models/Order.php` (`allowsDeliveryLink`, `allowsCourierReassignment`, `trackingUrl`, `isEditableInAttendanceBy`, `canConfirmDeliveryBy`), `app/Filament/Tenant/Support/OrderStatusActions.php` (visibilidade das Actions delega ao `Order`), `app/Filament/Tenant/Resources/Orders/Tables/OrdersTable.php` (atalho "Editar" usa a mesma regra), `app/Support/Fiscal/FlavorFraction.php` (`sliceLabel`), `app/Filament/Tenant/Pages/Orders/AttendOrder.php` (usa `sliceLabel`), `resources/views/components/orders/order-card.blade.php`, `resources/views/filament/tenant/pages/kitchen.blade.php`.
- **Sem migration, sem permissão nova.**
- **Testes:** os 32 `Kitchen*Test` seguem verdes, sem remoção. Pasta `tests/Feature/Orders` com 197 testes verdes.
- **Comparação com a origem (`diff -u` contra a cópia das views):** o card de cancelado só difere nos vínculos traduzidos. O card principal, a coluna e a página têm diferenças de adaptação ao modelo do RazelFood (itens com `quantity`/`unit_price`/`addons_total`/`note`, adicionais e brindes no formato já usado, `sale_id` para "Já pago", sabores por rateio da categoria) e as exceções da §4 (mesa, produção, oferta de promoção adicional). A página ainda não tem o que é da 1.S.2b e 1.S.2c (turno, seletor de colunas, som, impressão, PDFs).
- **Capturas lado a lado:** pendentes (feitas pelo usuário; não há navegador nesta sessão).
- **Achados da revisão corrigidos:** "Abrir no atendimento" aparecia para quem receberia 403 (agora usa `isEditableInAttendanceBy`, a mesma regra do `mount()` do AttendOrder); card cancelado sem `wire:key`; rateio de sabores com categoria apagada; "Confirmar entrega" para o Atendente quando o tenant não atribui entregador.
- **Limitações conhecidas:** `#id` no card segue o `id` interno, enquanto a comanda impressa usa `Order::displayNumber()` (número por estabelecimento). Há essa divergência já existente entre o board e a comanda; decisão pendente (D-PP-09, abaixo). Item de venda parcial (`order_items.sale_id`) não bloqueia o atalho "Abrir no atendimento"; o bloqueio vem ao salvar.
- **Pizzaria-App:** `git status` idêntico ao do início da sessão.

**1.S.2a — resultado da conferência visual (2026-11-09):** capturas lado a lado recebidas; a diferença visível restante eram os chips de navegação (nomes curtos do status), corrigida na 1.S.2b. O total do pedido no card foi dispensado pelo usuário. D-PP-09 adiado pelo usuário para depois da 1.S.2c.

### 1.S.2b — Turno, colunas e filtros (2026-11-09) · aguardando conferência visual e commit

- **Arquivos criados:** `tests/Feature/Orders/KitchenShiftAndColumnsTest.php` (29 testes).
- **Arquivos modificados:** `app/Filament/Tenant/Pages/Kitchen.php`, `app/Support/Orders/ResolveActiveShiftStart.php` (`windowForOpeningDay`), `resources/views/filament/tenant/pages/kitchen.blade.php`, `resources/views/filament/tenant/pages/kitchen/column.blade.php`, `tests/Feature/Orders/KitchenCardTest.php` (asserção do id `coluna-in-transit` que passava à toa).
- **Sem migration, sem permissão nova.** Preferência nova: `user_preferences` chave `kitchen.columns` (`{"hidden": [...]}`), por usuário e tenant.
- **Comportamento que muda (D-PP-01, aprovado):** as colunas em andamento só mostram o turno atual; pedidos de turnos anteriores ficam atrás do botão "N pedidos de turnos anteriores (mostrar)". Um período informado substitui a janela.
- **Coluna Finalizados:** seletor "Turno de", limite 50 (carregar mais até 200, com aviso de truncamento), "Ver histórico completo" mantido.
- **Colunas visíveis:** menu "Colunas" por usuário; nunca esconde todas; a coluna do filtro rápido não some; valores adulterados no navegador são saneados; colunas ocultas não são consultadas.
- **Achados da revisão tratados:** `finishedDate` congelado no `mount()`; janelas de turno sobrepostas/com lacuna e fronteira inclusiva; contador com período informado; filtro rápido com a coluna oculta; validação da data; saneamento das preferências; consultas desnecessárias por render (turno memoizado, colunas ocultas puladas); `wire:model` da data com debounce; botão "carregar mais" só quando há mais.
- **Limitações conhecidas:** a consulta de finalizados usa `COALESCE(...)` e varre os pedidos do tenant; se ficar lenta, um índice composto resolve (não necessário hoje). O dia operacional de uma data escolhida cobre os dois turnos de um dia com almoço e jantar, enquanto o padrão (turno atual) mostra só a partir da última abertura.
- **Capturas lado a lado:** pendentes (feitas pelo usuário). **Pizzaria-App:** `git status` idêntico ao do início.

Pendente: 1.S.2c.

### Decisão pendente descoberta na 1.S.2a

| ID | Decisão | Recomendação |
|---|---|---|
| D-PP-09 | O board e a busca usam o `id` interno do pedido (`#{{ $order->id }}`), mas a comanda impressa e `Order::displayNumber()` usam `order_number` (sequência por estabelecimento). Mostrar o mesmo número nos dois? | **Sim**, mostrar `displayNumber()` no card e fazer a busca por número casar com `order_number`. Muda busca e testes; fica para depois da 1.S.2c. |
