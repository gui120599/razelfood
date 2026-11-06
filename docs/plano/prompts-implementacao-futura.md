# Prompts para implementação futura

> Base: [migracao-funcionalidades-pizzaria-app-para-razelfood.md](migracao-funcionalidades-pizzaria-app-para-razelfood.md) e documentos específicos em `docs/plano/`.
> Use **um prompt por vez**, na ordem. Cada prompt pressupõe que o anterior foi aprovado e entregue.
> Todos valem para o repositório `/home/gui120599/razelfood`. O `/home/gui120599/Pizzaria-App` é **somente leitura** (referência de regras).

Bloco comum — **cole no início de qualquer prompt abaixo**:

```text
Contexto obrigatório:
- Leia CLAUDE.md, .ai/rules/index.md e TODAS as regras cujos globs cobrem os arquivos que você vai tocar (inclusive .ai/rules/general.md). Rode grep -rin nas regras pelas palavras-chave do módulo.
- Leia o documento principal docs/plano/migracao-funcionalidades-pizzaria-app-para-razelfood.md — OBRIGATORIAMENTE a §2.4 "Convenções obrigatórias do RazelFood" e as seções 10, 14, 16, 17 — e o documento específico indicado abaixo.
- O Pizzaria-App (/home/gui120599/Pizzaria-App) é SOMENTE LEITURA: NÃO altere nada lá. Back-end: leia para entender a regra e NÃO copie código PHP, nomes nem pastas (nomenclatura em português com prefixo de coluna, Services em vez de Actions, Laravel 10 structure e spatie v6 sem teams são diferentes). Front-end: COPIE os arquivos para o RazelFood conforme a §2.4.9.
- Siga a §2.4 à risca no código PHP e no banco: nomes em inglês (tabelas plural sem prefixo, enums TitleCase com valor snake_case e label() em pt-BR), Actions invocáveis em app/Actions/{Domínio}, clientes externos em app/Services/{Área}, exceções em app/Exceptions, models de domínio estendem TenantScopedModel, estrutura Resource/{Pages,Schemas,Tables}, PHPUnit (não Pest) sem factories de domínio, alvo PHP 8.4, Sail para todo comando, Pint no final. Os nomes de classes/tabelas dos documentos do plano são propostas: confirme-os contra os arquivos irmãos antes de criar.
- Telas: RazelFood e Pizzaria-App são o mesmo sistema. Toda tela do mapa de cópia da §2.4.9 nasce da cópia dos arquivos de front da origem (Blade, partials, componentes, Alpine, classes Tailwind, JS e tema). Não redesenhe nem recrie "parecido". Traduza só os vínculos com o back-end e aplique só as exceções da §2.4.9. Entregue o diff contra a origem e capturas lado a lado.
- Multi-tenant: toda tabela nova de domínio com tenant_id; credenciais com cast 'encrypted'; nenhum HTTP externo dentro de DB::transaction; job/command/webhook sempre com contexto de tenant explícito.
- Permissões novas: em config/filament-shield.php (custom_permissions) e em SeedDefaultTenantRoles, com teste de que sobrevivem à edição do papel pela UI.
- Banco: NUNCA migrate:fresh / --env=testing (não existe .env.testing). Testes via `sail artisan test` (RefreshDatabase no banco testing).
- Não altere nada fora do escopo deste prompt. Se encontrar algo que precise mudar fora dele, PARE e pergunte.
- Comece em modo plano: apresente o plano (arquivos, migrations, decisões em aberto via AskUserQuestion) e aguarde aprovação antes de escrever código.
- Ao final: suíte completa verde, Pint, query de sanidade confirmando que os tenants/usuários reais do dev (emporiodapizza, lazzopizza, admin@razeltec.com.br) seguem intactos, limpeza dos dados de teste criados, e um relatório em português (o que foi entregue, bugs encontrados, o que ficou de fora). Peça uma revisão final com o subagente laravel-reviewer antes de declarar concluído. Não faça commit sem autorização.
```

---

## Prompt 0 — Fundação multi-tenant para integrações (Fase 0)

```text
[bloco comum]

Módulo: Fase 0 do plano — fundação para integrações externas. Documento: docs/plano/migracao-funcionalidades-pizzaria-app-para-razelfood.md §10 (MT-01 a MT-12) e §14 (linha Fase 0).

Analise antes:
- app/Support/CurrentTenant.php, app/Models/Scopes/TenantScope.php, app/Models/Concerns/*, app/Http/Middleware/{ResolveTenantFromPath,ApplyTenantScopes}.php, config/tenancy.php, routes/web.php, bootstrap/app.php, app/Support/FeatureKey.php, database/seeders (Feature/Plan), app/Models/Tenant.php (recaptcha_secret_key).
- Referência: Pizzaria-App app/Http/Middleware/{VerifyNfeIoSignature,VerifyStoneWebhookAuth}.php e app/Http/Controllers/{NfeWebhookController,StoneWebhookController}.php (padrão "grava antes, decide depois").

Requisitos:
1. Trait/job middleware RunsInTenantContext: recebe tenant_id, seta CurrentTenant + PermissionRegistrar team + URL::defaults, executa, e SEMPRE faz CurrentTenant::forget() no finally. Helper equivalente para commands que iteram tenants ativos com uma feature ligada.
2. Padrão de rota de webhook fora do grupo {tenant}: prefixo /webhooks, sem CSRF, rate limit, resolvendo tenant por token opaco (coluna a ser criada nas fases seguintes — aqui só a infraestrutura e um teste com rota fake).
3. Adicionar 'webhooks', 'garcom' (e avaliar 'mesa') em config/tenancy.reserved_slugs, com teste de ValidTenantSlug.
4. Cast 'encrypted' em tenants.recaptcha_secret_key com migration de dados que criptografa os valores existentes (reversível) e teste que lê a coluna crua.
5. Nova FeatureKey STONE_CONNECT (reservada) e plano de ativação das features nfe_emissao e pdv (continuam is_available=false até as fases correspondentes — só preparar).
6. Propor (não aplicar sem aprovação) o texto novo do guardrail do CLAUDE.md e de docs/requisitos-regras-negocio.md §9/§11 (decisão D-02).

Critérios de aceite:
- Teste: job com RunsInTenantContext para tenant A só enxerga dados de A; após o job, CurrentTenant está nulo; job sem tenant_id falha explicitamente.
- Teste: command iterando 2 tenants grava em cada um sem cruzar.
- Teste: slug reservado recusado.
- Teste: secret criptografado no banco, legível pelo model.
Implementação incremental: 1 → 3 → 4 → 2 → 5 → 6.
```

---

## Prompt 1.0 — Fechamento de conta / Venda (pré-requisito da P1)

```text
[bloco comum]

Módulo: Fase 1.0 — Venda mínima ("Fechamento de conta"). Documento: principal §1 (itens 2–4), §3.2 (linha PDV/Venda), §4.1 (RN-PED-03, RN-PED-07), §4.2 (RN-VEN-01 a 06), §14 (Fase 1.0), decisão D-01.

Analise antes:
- RazelFood: app/Models/{Order,OrderItem,OrderPayment,PaymentOption}.php, app/Actions/Orders/*, app/Enums/OrderStatus.php, app/Filament/Tenant/Pages/{Kitchen,Orders/AttendOrder}.php, app/Filament/Tenant/Support/OrderStatusActions.php, app/Filament/Tenant/Widgets/Reports/* (o que conta como venda hoje).
- Referência (leitura): Pizzaria-App app/Services/{FinalizacaoVendaService,CancelamentoVendaService,VendaService,LancamentoItensVendaService,LancamentoPagamentosVendaService}.php, app/Models/{Venda,PagamentosVenda,ItensPedido}.php.

Requisitos:
1. Tabelas sales e sale_payments (com tenant_id), order_items.sale_id. Venda agrega 1+ pedidos (itens ainda sem sale_id); nada de itens avulsos nesta fase (confirmar com o usuário).
2. Pagamentos recebidos: forma (FK + snapshot do nome), valor, troco (só forma is_cash), campos opcionais para bandeira/autorização/terminal (preparados para a P2, sem UI de terminal).
3. Finalizar: pago ≥ total (tolerância de centavos), lock nos pedidos/itens, grava sale_id nos itens; cancelar venda: devolve pedidos a "a receber"; bloqueio de cancelamento quando houver documento fiscal vivo (gancho vazio até a P1).
4. Situação de pagamento do pedido derivada (pendente/pago) — NÃO alterar o significado de OrderStatus::Finished nem o fluxo de quem não usa a feature pdv (RN-PED-03).
5. UI no painel do tenant: "Contas a fechar" (pedidos entregues/prontos sem venda), tela de fechamento com pagamentos múltiplos e troco, lista de vendas. Gate pela feature pdv e permissão nova manage_sales.
6. Fora de escopo: sessão de caixa, fiado, alteração de venda finalizada, cortesia, divisão de item, desconto manual.

Critérios de aceite:
- Item não pode entrar em duas vendas (teste de concorrência por design + teste funcional).
- Soma de pagamentos − troco = total, centavo a centavo, em pagamento misto.
- Tenant sem feature pdv: nenhuma tela nova, Kitchen e relatórios inalterados (testes existentes passam).
- Isolamento entre dois tenants.
Implementação incremental: migrations+models → Actions (finalize/cancel) com testes → UI → permissões/feature.
```

---

## Prompt 1 — Integração NFE.io (Prioridade 1, Fases 1.1–1.4)

```text
[bloco comum]

Módulo: NFC-e via NFE.io. Documento específico: docs/plano/migracao-nfeio-razelfood.md (todas as seções; regras RN-FIS-01 a 20; desenho §7; critérios §10). Pré-requisito: Fase 1.0 entregue.

ANTES de codificar a Fase 1.2, confirme com o usuário as respostas da §11 do documento (perguntas à NFE.io) e a decisão D-03 (conta da Razel × conta do tenant). Não invente endpoints, status nem campos: use somente os comprovados no código do Pizzaria-App ou os que o usuário trouxer da documentação oficial.

Analise antes:
- Referência (leitura): Pizzaria-App app/Services/NfeIoService.php, app/Services/Nfe/{NfNumeracaoService,CompradorNfceService,EmissaoNfeAutomaticaService,ReenvioNfeService}.php, app/Jobs/EmitirNfeAutomaticaJob.php, app/Http/Controllers/NfeWebhookController.php, app/Http/Middleware/VerifyNfeIoSignature.php, app/Console/Commands/{NfeSincronizarStatus,NfeNumeracaoDefinir}.php, app/Models/{NfEmissao,NfNumeracao,NfeWebhook}.php, app/Support/RateioCentavos.php, tests/ relacionados a Nfe.
- RazelFood: entregas da Fase 0 e 1.0, app/Models/{Tenant,Product,Category,PaymentOption,Client}.php, app/Filament/Tenant/Pages/EstablishmentSettings.php, app/Rules/{ValidCpf,ValidCnpj}.php, tabela cities (ibge_code).

Fatie em 4 entregas, cada uma com aprovação:
1.1 Cadastro fiscal: tenant_fiscal_settings (credenciais encrypted, fora do cache de tenant), campos fiscais de produto (com defaults por categoria, se aprovado), fiscal_payment_code/auto_issue/requires_card_brand/requires_authorization em payment_options, PF/PJ/CNPJ/IE/e-mail em clients, FiscalReadiness com a lista do que falta. Só Simples Nacional (D-07).
1.2 Emissão: NfeIoClient (credenciais por parâmetro), fiscal_number_sequences (unique por CNPJ/ambiente/modelo/série), fiscal_documents, ReserveFiscalNumber, BuildNfcePayload (RN-FIS-06 a 11, com testes de centavos), IssueFiscalDocument (manual) + IssueFiscalDocumentJob (automática, RunsInTenantContext, lock RN-FIS-13), modal com polling no fechamento de conta, DANFE pela URI.
1.3 Ciclo de vida: webhook por tenant com HMAC do segredo do tenant (grava antes de decidir 403), fiscal_webhooks, comando de reconciliação tenant-aware no schedule (routes/console.php, compatível com cron de 1 min), cancelamento e reenvio com os bloqueios RN-FIS-16/17, gancho de bloqueio no cancelamento de venda.
1.4 Painel fiscal: FiscalDocumentResource (filtros por situação, ações reenviar/atualizar/cancelar/DANFE), lista de números a inutilizar, relatório fiscal simples, contador de notas por tenant na central.

Critérios de aceite: os 10 itens da §10 do documento específico, mais: nenhuma chamada real à NFE.io nos testes (Http::fake), nenhum segredo em log.
```

---

## Prompt 1.R — Cópia da tela de venda do Pizzaria-App

```text
[bloco comum]

Módulo: Fase 1.R — Nova Venda. Documento: docs/plano/migracao-funcionalidades-pizzaria-app-para-razelfood.md §2.4.9, §14 (linha 1.R) e decisão D-08.

Copie para o RazelFood (a origem é somente leitura):
- resources/views/filament/pages/operar-venda.blade.php
- resources/views/filament/pages/operar-venda/item-pedido.blade.php
- resources/views/livewire/venda-produto-selector.blade.php

Analise também: Pizzaria-App app/Filament/Pages/OperarVenda.php e app/Livewire/VendaProdutoSelector.php; RazelFood app/Filament/Tenant/Pages/Sales/{CloseAccount,PendingAccounts}.php, app/Actions/Sales/*, app/Support/Sales/*, app/Filament/Tenant/Support/{SaleActions,FiscalDocumentActions}.php, tests/Feature/Sales/*.

Requisitos:
1. Copie as views primeiro. Depois crie a página Nova Venda e o seletor em inglês, expondo o que as views precisam e chamando FinalizeSale, CancelSale e o ciclo fiscal.
2. Traduza só os vínculos com o back-end. Layout, classes, textos, abas, modais e atalhos ficam iguais.
3. Remova da cópia apenas o que ainda não existe: Mesas, Pendentes/fiado, cortesia, divisão de item, Stone e influenciador.
4. D-08 aprovada: a venda fica salva desde o 1º item, em um novo SaleStatus aberto (a Fase 2.3 reaproveita esse estado). Os demais fluxos de venda não mudam.
   - Item ou pedido lançado em venda aberta fica reservado a ela: outro operador não consegue lançá-lo em outra venda (lock, como hoje em FinalizeSale).
   - Número da venda, baixa de estoque, efeitos financeiros e emissão fiscal só acontecem ao finalizar.
   - Relatórios, telas, SaleCancellationGuard e CancelUnpaidSalesJob precisam tratar corretamente o novo estado; venda aberta não conta como venda realizada.
   - Rascunho vazio é descartado ao sair da tela (sendBeacon) e por comando agendado tenant-aware, como VendaCancelarVaziaController e vendas:cancelar-iniciadas-vazias no Pizzaria-App.
5. Tire CloseAccount/PendingAccounts da navegação e mantenha redirecionamento das URLs antigas. Apague os arquivos só depois da minha aprovação.

Critérios de aceite:
- diff de cada view contra a origem contendo só vínculos traduzidos e exceções da §2.4.9.
- Capturas lado a lado em 1920 px, 1366 px e celular.
- Testes de tests/Feature/Sales adaptados sem perder cobertura, incluindo isolamento entre tenants.
- Fechar conta com pedidos, com produtos avulsos e com pagamento misto, com Http::fake para a NFe.io nos testes.
```

---

## Prompt 1.S — Cópia das telas de pedido e conferência da Fase 1

```text
[bloco comum]

Módulo: Fase 1.S. Documento: §2.4.9 (linhas Atender Pedido, Painel de Pedidos, Vendas e fiscal, Menu) e decisão D-09. Pré-requisito: Fase 1.R aprovada.

Requisitos:
1. Atender Pedido: copie atender-pedido.blade.php e as views livewire pedido-produto-selector, cliente-picker e entrega-pagamento-picker, mais resources/js/pizza-svg*.js, ligando em AttendOrder, ProductCatalogSelector, ClientLookup, FulfillmentPicker e nos modais de sabores e adicionais, sem perder brindes.
2. Painel de Pedidos: copie painel-pedidos.blade.php e painel-pedidos/{card,coluna,cancelado-card,detalhes-modal,link-entrega-modal}.blade.php, ligando em Kitchen e OrderStatusActions, sem perder as configurações por tenant.
3. Vendas e fiscal: compare SaleResource, FiscalReport e documentos fiscais com as telas do Pizzaria-App e ajuste campos, colunas, filtros e rótulos.
4. Menu: D-09 aprovada. Use rótulos, grupos e ordem iguais aos do AdminPanelProvider, ocultando itens de funções que o RazelFood ainda não tem.

Critérios de aceite: diff contra a origem, capturas lado a lado e suíte de pedidos sem regressão.
```

---

## Prompt 2 — Connect Stone (Prioridade 2, Fases 2.1–2.4)

```text
[bloco comum]

Módulo: cobrança em maquininha via Connect Stone (Pagar.me v5). Documento específico: docs/plano/migracao-connect-stone-razelfood.md (estados §5, regras RN-STO-01 a 18, desenho §8, critérios §10). Pré-requisitos: Fases 1.0 e 1.1 (fiscal_payment_code); P1 recomendada para a NFC-e automática.

ANTES de codificar a Fase 2.2, confirme com o usuário as respostas da §8.1 (credenciais por lojista × parceiro, ServiceRefererName, webhook por conta). Não invente comportamento da API: use só o que está no código do Pizzaria-App ou na documentação que o usuário fornecer.

Analise antes:
- Referência (leitura): Pizzaria-App app/Services/Stone/{StoneConnectService,StoneRecebimentoService,StoneVendaAutomaticaService}.php, app/Http/Controllers/StoneWebhookController.php, app/Http/Middleware/VerifyStoneWebhookAuth.php, app/Console/Commands/StoneConciliarPedidos.php, app/Models/{StonePedido,StoneWebhook,Maquininha}.php, app/Enums/StonePedido*.php, app/Livewire/MesaStoneCobranca.php, migrations de stone_pedidos (índice stp_chave_pendente), docs/plano/plano-multiplas-maquininhas-connect-stone.md, docs/Roteiro-Testes-Homologacao-Stone.md, tests/ relacionados a Stone.
- RazelFood: entregas das Fases 0, 1.0, 1.1.

Fatie:
2.1 payment_terminals (CRUD no painel do tenant; provider, serial, connect_enabled) e uso opcional do terminal no pagamento manual do fechamento de conta.
2.2 tenant_payment_integrations (encrypted, webhook_token), StoneConnectClient, payment_terminal_charges com os 7 estados, ResolveAmountDue, CheckTerminalChargeEligibility, StartTerminalCharge (Direto e Listado, lote com vagas), Recheck/Cancel — HTTP sempre fora da transação, registro pendente antes do POST, Idempotency-Key estável, code próprio (avaliar prefixo por ambiente, R-ST-02).
2.3 Webhook /webhooks/stone/{token} (Basic Auth do tenant, grava antes de processar, responde 200 mesmo com erro de processamento), HandleChargePaid/HandleChargeRefunded (dedupe por charge, criação de venda a partir de pedido, finalização, NFC-e automática, estorno), comando de conciliação com os 6 cenários, tenant a tenant.
2.4 UI: "Cobrar na Stone" × "Lançar manual" dentro do modal de pagamento da Nova Venda, como em operar-venda.blade.php; envio do pedido (Listado); acompanhamento com polling; TerminalChargeResource e PaymentWebhookResource.

Critérios de aceite: os 11 itens da §10 do documento específico. Testes de timeout/5xx/4xx/webhook duplicado/webhook cruzado entre tenants obrigatórios.
```

---

## Prompt 3 — Painel do Garçom / Salão (Prioridade 3, Fases 3.1–3.4)

```text
[bloco comum]

Módulo: painel do garçom. Documento específico: docs/plano/painel-garcom-razelfood.md (requisitos RF-G01 a G15, arquitetura §4, cópia do front §5, integrações §6, critérios §7). Pré-requisitos: Fases 0, 1.0, 1.R e 1.S; P2 opcional (cobrança da mesa só aparece se ativa).

Decisões a confirmar com o usuário antes de começar: D-06 (painel separado /garcom/{slug}), status de "entregue na mesa" para dine_in (§4.4 — Ready→Delivered e Finished só com conta paga?), D-05 (taxa de serviço padrão).

Analise antes:
- Referência (leitura): Pizzaria-App app/Providers/Filament/GarcomPanelProvider.php, app/Filament/Garcom/Pages/{MapaMesas,AtenderMesa,AtenderRetirada}.php, app/Filament/Garcom/Concerns/*, app/Services/Garcom/{AtendimentoMesaService,MapaMesasService,RetiradaService,AutorizacaoGerenteService,PinService}.php, app/Services/SessaoMesaService.php, app/Support/ContaMesa.php, app/Enums/{StatusMapaMesaEnum,AcaoAutorizadaEnum,TipoMesaEnum}.php, config/pizzaria.php (seção salao), resources/views/filament/garcom/**.
- RazelFood: app/Providers/Filament/TenantPanelProvider.php, app/Filament/Tenant/Livewire/Orders/*, app/Filament/Tenant/Pages/Orders/AttendOrder.php, app/Actions/Orders/*, app/Filament/Tenant/Pages/Kitchen.php, docs/identidade-visual-design-system.md, .ai/rules/{orders,pages,tenant-pages,models-filament,users}.md.

Fatie:
3.1 dining_tables, table_sessions, table_session_people, orders.table_session_id/waiter_user_id, settings do salão por tenant, CRUD de mesas no painel do tenant, Actions OpenTableSession/TransferTableSession/CloseTableSession/BuildTableBill.
3.2 WaiterPanelProvider (tenancy nativa + ApplyTenantScopes persistente, papel Garçom, databaseNotifications), Mapa de mesas e Atender mesa copiados de resources/views/filament/garcom/pages/{mapa-mesas,atender-mesa}.blade.php e dos partials listados na §5, tema e configuração visual copiados de GarcomPanelProvider, rascunho persistido e envio idempotente pelo seletor copiado na Fase 1.S e pelas Actions de pedido, marcar entregue, retirada, selo "Mesa X" e aviso de pronto no Kitchen.
3.3 PIN de usuário (hash + rate limit), manager_authorizations, AuthorizeManagerAction, cancelar item / remover taxa / transferir mesa com autorização.
3.4 Conta por pessoa, pré-conta com o layout copiado do Pizzaria-App, "Contas a fechar" integrando com a Venda, botão de cobrança Stone da mesa (se P2 ativa).

Requisito de front: copiar os arquivos da §5 de docs/plano/painel-garcom-razelfood.md, sem redesenhar, com polling (sem WebSocket — HostGator). Entregar diff contra a origem e capturas lado a lado em desktop e celular emulado (ver memória "Chromium sem sudo").

Critérios de aceite: os 9 itens da §7 do documento específico.
```

---

## Prompt 4 — Painel da Cozinha / KDS (Prioridade 4)

```text
[bloco comum]

Módulo: KDS por setor. Documento específico: docs/plano/painel-cozinha-razelfood.md (regras §3, etapas §4, MT §5, critérios §6). Pré-requisito: P3 entregue (a etapa 4.1 já foi feita dentro da 3.2). Implementar SOMENTE as etapas 4.2 a 4.6 neste prompt; 4.7/4.8 e ficha técnica ficam para prompts futuros.

Analise antes:
- Referência (leitura): Pizzaria-App docs/modulo-cozinha.md (§3.3, §3.4, §5.1–5.7), app/Services/Cozinha/{RoteadorProducao,ProducaoService,SincronizadorPedidoProducao,PedidoStatusGateway,CozinhaTempoReal,PainelCozinhaService}.php, app/Observers/{PedidoProducaoObserver,ItemPedidoProducaoObserver}.php, app/Enums/Cozinha/*, app/Filament/Cozinha/Pages/{Estacao,Expedicao}.php, app/Support/Cozinha/SincronizacaoContexto.php.
- RazelFood: app/Models/{ProductionLine,Category}.php, category_production_line, app/Filament/Tenant/Pages/Kitchen.php, app/Actions/Orders/{AdvanceOrderStatus,UpdateOrderFromCart,CancelOrder}.php, .ai/rules/tenant-pages.md.

Requisitos:
4.2 Evoluir ProductionLine para setor (cor, ordem, tempo alerta/alvo) e products.skips_production.
4.3 production_items + production_events (tenant_id, snapshot, status por item, sem etapas múltiplas), gerados após o commit quando o pedido entra em Open, idempotente; sincronização com edição/cancelamento do pedido (cancela não iniciados; iniciados exigem "Ciente").
4.4 Página Estação copiada de resources/views/filament/cozinha/pages/estacao.blade.php, pages/estacao/card.blade.php e partials/operador.blade.php, com tema e configuração visual de CozinhaPanelProvider (tema escuro forçado, Fila → Em preparo → Prontos, filtro por setor, ações Iniciar/Pronto/Devolver/Desfazer com lock + versão), polling com contador de versão por tenant/setor.
4.5 Produção → pedido (1º iniciado → Preparing; todos prontos → Ready), nunca retrocedendo, com prevenção de laço.
4.6 Expedição copiada de resources/views/filament/cozinha/pages/expedicao.blade.php e producao-pedido-modal.blade.php + refazer com motivo.
Feature nova kds (reservada até a entrega) — tenant sem a feature mantém só o Kitchen atual.

Critérios de aceite: os 6 itens da §6 do documento específico (incluindo diff contra a origem e capturas lado a lado em tablet) + suíte atual do Kitchen sem regressão.
```
