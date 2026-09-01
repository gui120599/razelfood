# RazelFood — Regras de Negócio e Levantamento de Requisitos Inicial

**Produto:** RazelFood (produto próprio da Razel Tec Soluções e Consultoria em TI LTDA)
**Versão do documento:** 3.4
**Data:** 01/09/2026
**Status:** Revisado — arquitetura multi-tenant confirmada, tenancy por path, catálogo de features e planos por tenant, adicionais de produto, descrição/herança de sabores de categoria e CPF do cliente formalizados

> **Nota de versão (3.3 → 3.4):** set de features de ago/2026, já em produção.
> Cardápio: **RN-50** (descrição opcional por categoria, exibida no cardápio sob controle de um toggle) e **RN-51** (subcategoria pode herdar as quantidades de sabores da categoria pai). Checkout: **RN-52** (config por tenant "exigir CPF do cliente" — só no checkout online público). Requisitos funcionais novos: **RF-49/RF-50** (bulk actions de produto — replicar para outra categoria e ajustar preço em massa), **RF-51** (anexar vários adicionais de uma vez; cadastrar bairros de setor em lote), **RF-52** (export/import do catálogo de localidades no painel central), **RF-53** (acesso ao painel do tenant a partir da lista de tenants no painel central). Também: tenancy migrou de subdomínio para **path** (`razelfood.com.br/{slug}` e `/painel/{slug}`) — ver `modelagem-middleware-multitenant.md` nota 1.3→2.0; ajustes de UX no cardápio web (cabeçalho e barra de categorias fixos, 1ª forma de pagamento pré-preenchida com o total).

> **Nota de versão (3.2 → 3.3):** adicionada a seção 5.2 — adicionais de produto (RN-45 a RN-49, RF-45 a RF-48). Formaliza a venda de porções extras (ex.: "Bacon extra") vinculadas a produtos específicos do cardápio, com preço base e estoque próprios, e rateio proporcional ao sabor-alvo em produtos de vários sabores — reaproveitando o mesmo mecanismo de rateio já usado no preço/estoque de combos (RN-16), sem criar um cálculo paralelo. Decisão tomada em 22/08/2026.

> **Nota de versão (3.1 → 3.2):** adicionada a seção 4.1 — catálogo de features e planos por tenant (RN-39 a RN-44, RF-40 a RF-44). Formaliza como decididos os itens em aberto #6 (planos) e #11 (módulos avançados) da seção 11: a **infraestrutura** de planos/features entra em desenvolvimento agora; os módulos funcionais de PDV, controle de estoque e NF-e (emissão/entrada) **continuam fora do escopo do pacote padrão** (seção 9) — entram no catálogo só como reserva de roadmap, sem implementação funcional nesta fase. Decisão tomada em 20/08/2026.

> **Nota de versão (3.0 → 3.1):** adicionada a seção 6.2 — busca de endereço por CEP no checkout (via ViaCEP) e nova regra de taxa de entrega por setor/bairro, configurável por tenant, substituindo a taxa fixa única por opção de entrega para modalidades de entrega (retirada continua com taxa fixa). Decisão tomada em 20/08/2026.

**Fontes utilizadas:**
- Proposta Comercial RazelFood — Sistema de Pedidos Online (18/08/2026)
- Sistema de referência em produção: `github.com/gui120599/Pizzaria-App` (Laravel 12 + Filament 4) — usado como **especificação de regras de negócio já validadas em operação real**, não como código herdado
- Identidade visual RazelTec e RazelFood (logos fornecidos)
- Domínio próprio adquirido: `razelfood.com.br`

> **Nota de versão (2.0 → 3.0):** a v2.0 assumia, por engano, um modelo de instância dedicada por cliente (um deploy separado por pizzaria). Isso foi corrigido: **o RazelFood é multi-tenant desde a concepção**, construído do zero (não é um fork do Pizzaria-App), reaproveitando dele apenas classes, padrões e regras de negócio já validados em produção como referência de implementação. Cada cliente (tenant) terá seu cardápio público em um subdomínio próprio sob o domínio adquirido `razelfood.com.br` (ex.: `emporiodapizza.razelfood.com.br`). Esta seção 4 foi reescrita para refletir essa decisão.

---

## 1. Introdução

Este documento consolida o levantamento de requisitos e as regras de negócio do RazelFood, sistema de pedidos online da Razel Tec para restaurantes, pizzarias e delivery de pequeno/médio porte. Ele serve como base para o time de desenvolvimento (Laravel + Livewire + Filament) e para validação de escopo com clientes e diretoria.

O RazelFood é um **projeto novo, construído do zero, nativamente multi-tenant**. Ele usa como referência de regras de negócio e padrões de implementação já testados em produção o sistema `Pizzaria-App` — que hoje opera como sistema single-tenant completo de gestão de pizzaria (cardápio, pedidos, cozinha, clientes, promoções, caixa, estoque, compras, fiscal). O RazelFood **não herda o código desse sistema diretamente**: as regras de cardápio, pedidos, promoções e cozinha descritas neste documento foram extraídas de lá como especificação de comportamento já validado, para serem reimplementadas de forma nativamente multi-tenant. Módulos mais pesados do sistema de referência (caixa, estoque avançado, fiscal/NFe, compras) não fazem parte do escopo atual do RazelFood — ver seção 9.

Cada requisito funcional recebe um código (RF-XX) e cada regra de negócio um código (RN-XX) para rastreabilidade.

---

## 2. Visão Geral do Produto

O RazelFood existe para o dono de restaurante que precisa de um cardápio digital funcional, fácil de manter e com suporte de verdade — sem pagar por recursos que nunca vai usar. Concorrentes como Goomer, Anota AI e o cardápio nativo do iFood tendem a ser caros, engessados ou pensados para operações maiores; o diferencial do RazelFood é preço e simplicidade acessíveis para PME, sem abrir mão dos recursos essenciais — e, como o produto se apoia em regras de negócio já validadas em operação real, os recursos essenciais vêm testados em produção, não em teoria.

**Público-alvo:** donos/gestores de restaurantes, pizzarias, lanchonetes e operações de delivery de pequeno/médio porte, que tocam o operacional no dia a dia e não têm equipe de TI própria.

**Como funciona, em quatro passos (conforme a proposta comercial):**
1. Cliente acessa o cardápio digital do estabelecimento (subdomínio próprio) pelo celular.
2. Monta o pedido no carrinho.
3. Confirma e é direcionado ao WhatsApp do estabelecimento, com mensagem já estruturada.
4. A cozinha prepara e atualiza o status do pedido em tela própria.

**Premissa de design de produto:** qualquer funcionalidade proposta deve passar pelo filtro "isso resolve uma dor real do dono de restaurante sem complicar o dia a dia dele?". Módulos mais avançados (fiscal, estoque, financeiro completo) só entram no roadmap do RazelFood se fizerem sentido para o produto como um todo — diferente do modelo antigo (instância por cliente), agora uma funcionalidade nova vale para todos os tenants de uma vez, então o critério de "vale a pena construir" fica mais rigoroso.

---

## 3. Atores / Perfis de Usuário

Os perfis abaixo se inspiram nas roles já validadas no sistema de referência (Filament Shield + Spatie Permission), adaptadas para um contexto multi-tenant: cada usuário (exceto a equipe interna da Razel Tec) pertence a exatamente um tenant, e papéis/permissões são sempre avaliados dentro do escopo do tenant do usuário logado.

| Ator | Descrição | Permissões típicas |
|---|---|---|
| **Admin** | Dono/gestor do estabelecimento. Acesso completo ao painel do próprio tenant. | Todas as permissões dentro do próprio tenant |
| **Gerente** | Responsável operacional com acesso amplo, mas não necessariamente o dono. | Cancelar/aceitar/rejeitar/avançar/entregar pedido, estornar pagamento, aprovar lançamento, abrir/fechar caixa, ver financeiro (do próprio tenant) |
| **Atendente** | Recebe e processa pedidos no dia a dia. | Cancelar/aceitar/rejeitar/avançar pedido (sem financeiro) |
| **Caixa** | Opera abertura/fechamento de caixa (se o módulo estiver habilitado). | Abrir/fechar sessão de caixa |
| **Entregador** | Executa a entrega e atualiza o pedido. | Marcar pedido como entregue (via link/QR assinado) |
| **Cliente Final** | Consumidor que acessa o cardápio público (subdomínio do tenant) e faz o pedido. | Sem login formal — identificado por telefone (RN-01) |
| **Razel Tec (equipe interna)** | Responsável por provisionar novos tenants, configurar e dar suporte. | Acesso multi-tenant: cria/gerencia tenants, dá suporte a qualquer cliente |

> **RN-01:** Cliente Final não cria conta nem faz login formal. No checkout, o sistema busca automaticamente um cadastro existente pelo número de telefone informado, dentro do tenant daquele cardápio; se encontrar, reaproveita nome/endereço salvos (e permite atualizá-los); se não encontrar, cria um cadastro novo de cliente. Isso evita re-digitação em pedidos recorrentes sem exigir senha.

---

## 4. Arquitetura Multi-tenant e Modelo Comercial

> **Atualização (ago/2026) — TENANCY POR PATH:** o tenant deixou de ser identificado por **subdomínio** e passou a ser identificado por **path**, num domínio único: cardápio público em `razelfood.com.br/{slug}` e painel do tenant em `razelfood.com.br/painel/{slug}` (tenancy nativa do Filament). Motivo: eliminar a dependência de wildcard DNS + wildcard SSL na HostGator, que era o maior risco técnico. Provisionar um tenant virou um `INSERT` em `tenants`, sem configuração de infra. As menções a "subdomínio" abaixo e em RN-03/RN-04, RF-02, RNF-02 e no glossário ficam como registro histórico — leia "path" onde estiver escrito "subdomínio". Detalhes em `modelagem-middleware-multitenant.md` (nota 1.3→2.0).

- **RN-02:** O RazelFood é multi-tenant desde a concepção: uma única aplicação (e, recomendado, um único banco de dados) atende todos os clientes, com isolamento lógico garantido por um identificador de tenant presente em toda tabela de domínio (produtos, categorias, pedidos, clientes, promoções, horários, opções de entrega, usuários etc.), aplicado automaticamente via escopo global em todas as consultas.
- **RN-03:** Cada tenant é identificado por um **path** sob o domínio `razelfood.com.br`, baseado em um slug definido no onboarding — cardápio em `razelfood.com.br/{slug}`, painel em `razelfood.com.br/painel/{slug}`. O slug da URL é o que determina qual tenant (e qual cardápio) é servido. *(Histórico: até ago/2026 era um subdomínio, ex.: `emporiodapizza.razelfood.com.br`.)*
- **RN-04:** O slug do tenant deve ser único, seguir um formato controlado (minúsculas, sem espaços ou acentos, apenas letras/números/hífen) e não pode coincidir com uma lista de palavras reservadas (`www`, `admin`, `app`, `api`, `painel`, `suporte`, `blog`, etc.), para não colidir com rotas do próprio sistema (em especial `admin` e `painel`, que são segmentos de rota reais).
- **RN-05:** O slug é tratado como praticamente imutável após a publicação do cardápio — o cliente divulga esse link em QR code de mesa, cardápio impresso e conversas de WhatsApp; alterá-lo depois quebra tudo isso. Mudança de slug só deve ocorrer via processo assistido pela Razel Tec, avisando o cliente do impacto.
- **RN-06:** Domínio próprio do cliente (ex.: `cardapio.emporiodapizza.com.br` apontando via CNAME para o RazelFood) é um recurso possível a ser oferecido futuramente, não obrigatório para o lançamento — ver itens em aberto.
- **RN-07:** A hospedagem, disponibilização online, manutenção de infraestrutura e atualizações técnicas continuam sob responsabilidade da Razel Tec — agora compartilhadas entre todos os tenants em uma única aplicação, o que reduz o custo marginal de suporte a cada cliente novo (motivo central da mudança de arquitetura em relação à v2.0 deste documento).
- **RN-08:** O modelo de cobrança em vigor não muda com a arquitetura: implantação (pagamento único) referente à configuração e disponibilização inicial, mais mensalidade fixa recorrente enquanto o serviço estiver ativo, sem comissão por pedido e sem taxas adicionais além das descritas na proposta comercial vigente com o cliente. A mudança para multi-tenant é uma decisão de arquitetura interna; não implica, por si só, em novo modelo de preço.
- **RN-09:** O onboarding de um novo tenant continua sendo conduzido pela equipe da Razel Tec (não é self-service hoje): reserva e validação do slug, configuração do estabelecimento, horários de funcionamento, cadastro de categorias e produtos, configuração do número de WhatsApp de recebimento, testes do fluxo completo e orientação básica de uso. Onboarding self-service (cliente contrata e provisiona sozinho) é uma evolução possível — ver itens em aberto.
- **RN-10:** O cliente é responsável por fornecer, para a implantação: nome e informações comerciais do estabelecimento, logo (se possuir), fotos/nomes/descrições dos produtos, preços e categorias, horários de funcionamento e o número de WhatsApp que receberá os pedidos. A qualidade desse material impacta diretamente a apresentação final do cardápio.

---

### 4.1 Catálogo de Features e Planos por Tenant

> **Regra nova (v3.2, decidida em 20/08/2026):** o RazelFood passa a controlar, por tenant, quais funcionalidades do sistema estão disponíveis, via um catálogo de features agrupado em planos. Isso é infraestrutura de produto — não implica, sozinho, em mudar o modelo comercial vigente (RN-08 continua valendo: implantação + mensalidade fixa, sem tiers de preço definidos ainda). A composição comercial dos planos (o que cada plano custa, se muda a mensalidade) é decisão de diretoria, fora do escopo técnico deste documento.

- **RN-39:** O sistema mantém um catálogo de features (`features`), administrado pela equipe Razel Tec. Cada feature tem uma chave técnica estável e um indicador de disponibilidade (`is_available`) — uma feature pode existir no catálogo como reserva de roadmap antes de ter qualquer implementação funcional (RN-42). Catálogo atual: `cardapio_digital`, `configuracoes_estabelecimento`, `central_de_pedidos` (kanban de acompanhamento em tempo real), `historico_de_pedidos`, `configuracoes_de_pedidos` (alertas de atenção/atraso, regra de bairro não configurado), `linhas_de_producao`, `usuarios_permissoes` (papéis/permissões da equipe) — todas disponíveis desde já; `pdv`, `estoque`, `nfe_emissao`, `nfe_entrada` reservadas (RN-42).
- **RN-40:** Um plano (`plans`) é um conjunto nomeado de features, definido pela Razel Tec (ex.: "Essencial" = cardápio digital + produtos + configurações; "Completo" = Essencial + PDV + estoque + NF-e, quando essas features estiverem disponíveis). Todo tenant está associado a exatamente um plano.
- **RN-41:** Além do plano, um tenant pode ter overrides pontuais por feature (`tenant_feature_overrides`) — ligar uma feature que o plano não inclui, ou desligar uma que o plano inclui, sem precisar trocar o tenant de plano inteiro. A feature efetiva de um tenant é: o override, se existir; senão, o que o plano define.
- **RN-42:** Uma feature marcada como indisponível no catálogo (`is_available = false`) nunca aparece como habilitada para nenhum tenant, mesmo que conste no plano ou tenha override — é reserva de nome/roadmap, não uma feature liberável antes de ter implementação real por trás. É o caso hoje de PDV, controle de estoque e NF-e (emissão/entrada) — ver seção 9.
- **RN-43:** O controle de acesso por feature é reforçado em duas camadas, nunca só uma: navegação do painel (o tenant não vê no menu o que não tem) **e** autorização de acesso direto (a tentativa de acessar a URL/rota da funcionalidade é bloqueada mesmo que não apareça no menu).
- **RN-44:** Criar/editar planos e atribuir plano/overrides a um tenant é operação restrita à equipe interna da Razel Tec, feita no painel central — nunca self-service pelo próprio tenant (consistente com RN-09, onboarding assistido).

---

## 5. Regras de Negócio — Cardápio e Precificação

- **RN-11:** Um produto só aparece no cardápio público se estiver marcado como visível **e** (não controla estoque, **ou** tem saldo em estoque maior que zero, **ou** está configurado para aparecer mesmo com estoque zerado).
- **RN-12:** Categorias podem ter hierarquia de **um nível** (categoria-mãe e subcategorias — sem sub-subcategoria). Uma subcategoria só aparece no cardápio se tiver ao menos um produto visível, evitando seções vazias. Subcategoria é gerenciada tanto pela aba "Subcategorias" da categoria-mãe quanto por página de edição própria (com a aba "Quantidades de sabores" quando não estiver herdando — RN-51).
- **RN-13:** O preço de um item **nunca é aceito do cliente/navegador** — o servidor sempre recalcula o valor de cada item no checkout, dentro do escopo do tenant correspondente. Regra de segurança e de integridade financeira, não apenas de UX.
- **RN-14:** Ordem de precedência de preço de um produto, da mais forte para a mais fraca: (1) promoção relâmpago vigente e com saldo; (2) preço promocional do próprio produto, dentro da janela de datas; (3) preço de venda normal.
- **RN-15:** "Mais vendidos" é um destaque **calculado automaticamente** por tenant (top produtos por quantidade vendida, dentre os elegíveis), não uma lista editada manualmente — o Admin só liga/desliga a elegibilidade do produto.
- **RN-16:** Itens com "sabores" (ex.: pizza meio a meio, terço) só podem ser combinados numa quantidade que exista como opção cadastrada na categoria (`flavor_quantity_options`, definida pelo Admin do tenant — não é uma lista fixa do sistema). Uma opção com quantidade 1 ("sabor único") é tratada como item simples, não como combo. O preço do combo é rateado proporcionalmente entre os sabores escolhidos, calculado no servidor. Se um sabor pertence a uma promoção relâmpago vigente com "permite sabores" desmarcado, ele só pode ser vendido inteiro — fica de fora de qualquer combo (2+ sabores), mesmo que a categoria permita; se a promoção permite sabores mas define seu próprio teto, esse teto prevalece sobre o da categoria quando mais restritivo.
- **RN-50 (01/09/2026):** Cada categoria (raiz ou subcategoria) pode ter uma **descrição** livre e opcional (ex.: "Serve até 2 pessoas"), exibida abaixo do nome da categoria no cardápio público. A exibição é controlada por um toggle independente do texto — o Admin pode escrever a descrição e deixá-la oculta, ou ligá-la depois. Por padrão a descrição vem **oculta** (o toggle nasce desligado).
- **RN-51 (01/09/2026):** Uma **subcategoria** com "permite sabores" ligado pode **herdar as quantidades de sabores da categoria pai** (`flavor_quantity_options` do pai) em vez de cadastrar as suas próprias. O Admin escolhe por subcategoria: o toggle "Herdar da categoria pai" aparece quando o pai tem ao menos uma opção cadastrada, e vem **marcado por padrão** em subcategoria nova (caso comum = mesmas regras do pai). Desligá-lo revela a aba "Quantidades de sabores" da subcategoria para cadastro próprio. O cardápio, o checkout e a Central de Pedidos leem sempre as opções **efetivas** (do pai quando herdando, senão as próprias) — nunca as opções cruas da subcategoria.

### 5.1 Promoções Relâmpago

O sistema de referência já valida em produção um motor de promoções relâmpago mais sofisticado do que "desconto por tempo limitado simples". Documentamos essas regras aqui porque o RazelFood deve reimplementá-las (agora multi-tenant), não simplificá-las:

- **RN-17:** Uma promoção relâmpago pode ser pontual (janela fixa de início/fim) ou recorrente (dias da semana + horário de início/fim, incluindo janelas que atravessam a meia-noite, ex.: 22h–02h).
- **RN-18:** Uma promoção relâmpago pode ter um teto de unidades disponíveis (pool) por tenant; quando esgotado, some do cardápio automaticamente até resetar (se recorrente) ou até acabar (se pontual). Sem teto, fica sempre disponível dentro da janela de vigência.
- **RN-19:** É possível configurar um limite de unidades por pedido para uma promoção, evitando que um único cliente esgote o estoque promocional sozinho.
- **RN-20:** É possível exibir um contador de escassez ("restam X unidades"), mas só a partir de um limiar configurável — "restam 38 de 40" não cria urgência nenhuma.
- **RN-21:** O status de uma promoção relâmpago (inativa, agendada, ativa, esgotada, encerrada, aguardando janela) é sempre calculado em tempo real, nunca armazenado.
- **RN-22:** A baixa de saldo da promoção e a criação do pedido acontecem na mesma transação de banco — se a última unidade for vendida em pedidos concorrentes (dentro do mesmo tenant), apenas um deles confirma.

### 5.2 Adicionais de Produto

- **RN-45:** O Admin cadastra adicionais reutilizáveis (ex.: "Bacon extra", "Catupiry extra"), cada um com nome, descrição opcional e preço base — um adicional não vinculado a nenhum produto não fica disponível pra pedido em lugar nenhum.
- **RN-46:** O vínculo entre um adicional e um produto específico decide se ele fica disponível pra pedido daquele produto, e pode opcionalmente sobrescrever o preço base do adicional só pra esse vínculo, e limitar a quantidade máxima de porções por linha de carrinho. Sem sobrescrita, vale o preço base; sem limite configurado, não há teto.
- **RN-47:** Adicionais têm controle de estoque próprio, com a mesma semântica de produto (RN-24) — controla ou não estoque, quantidade em estoque, exibir mesmo sem estoque — e contagem própria de vendas (equivalente a RN-15), independente do produto ao qual estão anexados.
- **RN-48:** Ao pedir um adicional, o cliente escolhe uma quantidade de porções e, quando o produto pedido é um combo de sabores (RN-16), escolhe também se o adicional se aplica ao produto **inteiro** ou a **um único sabor** específico — nunca a mais de um sabor na mesma seleção. O preço e o consumo de estoque do adicional são calculados a partir da MESMA fração de rateio já configurada em `flavor_quantity_options` pra combos (RN-16): se aplicado a um sabor específico, multiplica-se pela fração daquele sabor; se aplicado ao produto inteiro, vale o valor cheio (fração de 100%). Exemplo: um adicional de R$ 6,00 aplicado a um sabor de uma pizza meio a meio 50/50 custa R$ 3,00; aplicado à pizza inteira, custa R$ 6,00. Não existe rateio próprio calculado pra adicionais — é sempre o mesmo percentual já definido pros sabores do produto.
- **RN-49:** O preço e a disponibilidade de um adicional seguem a mesma regra de segurança da RN-13 — nunca aceitos do cliente/navegador, sempre recalculados no servidor a partir do adicional, do vínculo com o produto e, se aplicável, da fração de sabor, no momento do checkout.

---

## 6. Regras de Negócio — Pedidos e Checkout

- **RN-23:** O sistema não aceita novos pedidos fora do horário de funcionamento do tenant. O bloqueio é sinalizado desde a escolha do primeiro item no cardápio (20/08/2026: `Menu::addToCart()`/`startCombo()`/`confirmCombo()` não adicionam nada ao carrinho com o estabelecimento fechado, abrindo direto o carrinho com o aviso), não só ao tentar finalizar — mas a validação autoritativa continua sendo sempre no servidor, no checkout (`CreateOrderFromCart`), nunca confiando só no bloqueio de UI. O cliente recebe uma mensagem informando o próximo horário de abertura (ex.: "Voltamos hoje às 18:00").
- **RN-24:** Controle de estoque é opcional por produto — o Admin decide, produto a produto, se ele deve bloquear o checkout sem saldo suficiente ou ser vendido livremente.
- **RN-25:** Toda promoção aplicada a um item do carrinho respeita o limite por pedido (RN-19), validado no servidor antes de gravar o pedido.
- **RN-26:** O pedido é registrado no sistema (com status inicial, associado ao tenant correto) antes do redirecionamento ao WhatsApp — o WhatsApp é o canal de confirmação humana, não o sistema de registro. O pedido já existe e é rastreável mesmo que o cliente feche a aba sem enviar a mensagem.
- **RN-27:** A mensagem enviada ao WhatsApp do estabelecimento é gerada automaticamente com: número do pedido, itens e valores, desconto, taxa de entrega, total, modalidade de entrega, endereço, forma de pagamento (com troco, se em dinheiro) e link de acompanhamento em tempo real. O número de WhatsApp de destino é configurado por tenant.
- **RN-28:** Cada cliente final tem uma página de acompanhamento do próprio pedido em tempo real (link enviado junto da mensagem de WhatsApp), sem necessidade de login.
- **RN-29:** Pode haver verificação anti-robô (reCAPTCHA) no checkout, configurável por tenant.
- **RN-30:** Toda opção de entrega (`delivery_options`) tem um tipo — retirada (sem entrega) ou entrega (com endereço) — configurado pelo tenant. Para opções de retirada, a taxa continua fixa (normalmente zero). Para opções de entrega, a taxa deixa de ser fixa por opção e passa a ser resolvida pelo bairro do cliente (ver 6.2). Em ambos os casos, continua existindo a possibilidade de isenção acima de um valor mínimo de pedido, configurável por tenant.
- **RN-31:** O cancelamento de um pedido exige motivo categorizado (cliente desistiu, erro de lançamento, produto indisponível, demora, duplicado/teste, problema no pagamento, endereço fora da área, outro) — alimenta relatórios de motivo de cancelamento por tenant.
- **RN-52 (01/09/2026):** O tenant pode exigir o **CPF do cliente** para finalizar um pedido. É uma configuração por tenant (Configurações de Pedidos), desligada por padrão. Quando ligada, o campo de CPF aparece no **checkout online público** e é obrigatório, com validação de dígito verificador; quando desligada, o campo nem aparece. A exigência **não afeta a Central de Pedidos** (atendente/PDV) — lá o CPF continua opcional, inclusive em "pedido sem cliente". O CPF é gravado só com os 11 dígitos (sem máscara) no cadastro do cliente (`clients.cpf`) e reaproveitado quando o mesmo cliente pede de novo (RN-01).

### 6.1 Ciclo de Vida do Pedido

O status do pedido segue um fluxo definido no sistema, com data/hora registrada em cada transição (permitindo medir tempo de preparo, tempo de entrega etc.):

| Status técnico | Equivalente comercial | Descrição |
|---|---|---|
| `INICIADO` | — | Pedido criado no checkout, ainda não confirmado/aceito |
| `ABERTO` | Pedido Aceito | Estabelecimento confirmou o recebimento |
| `PREPARANDO` | Em Preparação | Cozinha iniciou o preparo |
| `PRONTO` | Pronto Aguardando Entrega | Preparo concluído, aguardando saída/retirada |
| `EM TRANSPORTE` | Em Transporte | Pedido a caminho com o entregador |
| `ENTREGUE` | Entregue / Finalizado | Entregue ao cliente |
| `FINALIZADO` | Entregue / Finalizado | Pedido concluído (inclui retirada/consumo local) |
| `CANCELADO` | — | Cancelado, com motivo categorizado registrado |

- **RN-32:** A transição de status é restrita por permissão, sempre dentro do tenant do usuário: aceitar, rejeitar e avançar são ações de Atendente/Gerente/Admin; marcar como entregue é ação típica do Entregador (inclusive via link assinado/QR code específico, sem precisar de login completo no painel).

### 6.2 Endereço por CEP e Taxa de Entrega por Setor/Bairro

> **Regra nova (v3.1, decidida em 20/08/2026):** a taxa de entrega deixa de ser um valor único por opção de entrega e passa a variar conforme o bairro do cliente, configurável por tenant. É um recurso comercial relevante do SaaS — cada tenant define sua própria malha de entrega e preços, sem depender da Razel Tec para isso.

- **RN-33:** No checkout do carrinho, o cliente pode informar o CEP para que o sistema busque automaticamente o endereço (logradouro, bairro, cidade, UF) via serviço externo (**ViaCEP** — não a API do IBGE, que não cobre esse caso de uso). A busca é auxiliar: se o CEP não for encontrado ou o serviço externo estiver indisponível, o cliente preenche os campos manualmente, sem bloqueio do checkout. A mesma busca será reaproveitada futuramente no cadastro de cliente do painel (item em aberto #12).
- **RN-34:** O Admin cadastra, por tenant, **setores de entrega**: cada setor tem um nome livre (ex.: "Centro", "Zona Sul") e uma taxa de entrega definida pelo próprio tenant. Um setor agrupa um ou mais bairros.
- **RN-35:** Cada bairro cadastrado pertence a, no máximo, um setor por tenant — não é possível o mesmo bairro estar em dois setores do mesmo tenant.
- **RN-36:** O Admin define, por tenant, se atende pedidos de bairros que não estão associados a nenhum setor cadastrado. Quando atende, configura também uma taxa específica para esses casos, normalmente maior que as taxas dos setores mapeados — reflete o custo/risco extra de atender fora da área usual de entrega.
- **RN-37:** Resolução da taxa de entrega no checkout (sempre calculada no servidor, nunca aceita do navegador — reforça RN-13), nesta ordem:
  1. O bairro do cliente corresponde a um setor cadastrado → aplica a taxa desse setor (RN-34).
  2. O bairro não corresponde a nenhum setor **e** o tenant atende bairros não configurados (RN-36) → aplica a taxa normal da opção de entrega (RN-30) **somada** à taxa específica de bairro não configurado (RN-37a); o pedido é sinalizado (mensagem de WhatsApp e painel) como fora da área mapeada, e cabe ao atendente confirmar a viabilidade da entrega após o recebimento do pedido, podendo cancelar com o motivo já existente "endereço fora da área" (RN-31) se for inviável.
  3. O bairro não corresponde a nenhum setor **e** o tenant não atende bairros não configurados → o checkout é bloqueado para aquele endereço antes da criação do pedido, informando ao cliente que a entrega não está disponível para o bairro informado.
- **RN-37a (decidida em 20/08/2026):** no caso 2 da RN-37, a taxa de bairro não configurado **não substitui** a taxa normal da opção de entrega — ela é **somada** a ela. O checkout mostra as duas parcelas ao cliente antes de confirmar (taxa de entrega + taxa de área não mapeada = total), nunca só o total, para que ele entenda a cobrança (RF-39).
- **RN-38:** A isenção por valor mínimo de pedido (RN-30) é avaliada sobre a taxa normal da opção de entrega, antes de somar a taxa de bairro não configurado (RN-37a) — se o total de itens atingir o mínimo configurado, só a parte normal zera; a taxa de bairro não configurado, quando aplicável, continua sendo cobrada sozinha (ela não representa a taxa "de entrega" que o mínimo isenta, e sim um custo extra de atender fora da área mapeada).

---

## 7. Requisitos Funcionais

### 7.1 Módulo: Multi-tenant / Provisionamento

| ID | Requisito |
|---|---|
| RF-01 | O sistema deve permitir provisionar um novo tenant (com slug reservado e validado) sem exigir alteração manual de schema de banco de dados. |
| RF-02 | O sistema deve resolver automaticamente o tenant da requisição a partir do subdomínio e aplicar o isolamento de dados (escopo por tenant) em todas as consultas, de forma transparente para o restante do código. |
| RF-03 | A equipe da Razel Tec deve ter uma tela/ferramenta interna para listar tenants ativos, seus slugs, status e dados de contato, para fins de suporte. |
| RF-04 | O sistema deve rejeitar a criação de um slug que já exista ou que conste na lista de palavras reservadas (RN-04). |

### 7.2 Módulo: Cardápio Digital

| ID | Requisito |
|---|---|
| RF-05 | O Admin deve poder criar, editar, reordenar (drag-and-drop) e excluir categorias, incluindo subcategorias (um nível — RN-12), dentro do próprio tenant. Cada categoria suporta uma descrição opcional exibida no cardápio sob controle de um toggle (RN-50). |
| RF-06 | O Admin deve poder criar, editar, duplicar e excluir produtos, cada um vinculado a uma categoria do próprio tenant. O select de categoria do formulário agrupa as subcategorias sob a categoria pai (subcategorias de pais diferentes podem ter o mesmo nome). |
| RF-07 | Cada produto deve suportar: nome/descrição, preço de venda, foto (upload), indicador de disponibilidade e visibilidade no cardápio. |
| RF-49 | O Admin deve poder selecionar vários produtos na listagem e **replicar** as cópias para outra categoria/subcategoria de uma vez (originais mantidos, adicionais vinculados vão junto, `sales_count` zerado). |
| RF-50 | O Admin deve poder selecionar vários produtos na listagem e **ajustar o preço em massa**: definir um valor fixo, aplicar porcentagem (aumentar/diminuir) ou somar/subtrair um valor em R$; opcionalmente aplicar o mesmo ajuste ao preço promocional. O preço nunca fica negativo. |
| RF-51 | Ações em lote de configuração do cardápio: anexar **vários adicionais de uma vez** a um produto (RN-46), e cadastrar **vários bairros de uma vez** num setor de entrega (RN-34), lembrando a última cidade usada. |
| RF-08 | O Admin deve poder configurar produtos com suporte a "sabores" (combos tipo pizza meio a meio). Por categoria, o Admin cadastra as quantidades de sabores aceitas (ex.: "Sabor único" = 1, "Meio a meio" = 2), cada uma com rótulo livre — não é uma lista fixa do sistema. |
| RF-09 | O Admin deve poder marcar um produto como indisponível temporariamente, ou usar controle de estoque para isso automaticamente (RN-24). |
| RF-10 | O cardápio público deve ser responsivo (mobile-first) e carregar rápido mesmo em conexões ruins. |
| RF-11 | O sistema deve informar automaticamente se o estabelecimento está aberto/fechado, com horários configuráveis por dia da semana, por tenant. |
| RF-12 | O Admin deve poder marcar produtos elegíveis ao destaque "mais vendidos", calculado automaticamente (RN-15). |
| RF-13 | O Admin deve poder configurar preço promocional em um produto, com janela de datas de início/fim. |
| RF-14 | O Admin deve poder criar e gerenciar promoções relâmpago (pontuais ou recorrentes), com teto, limite por pedido e contador de escassez (seção 5.1). |
| RF-15 | O cardápio público deve destacar promoções relâmpago vigentes, com contador de escassez quando aplicável. |
| RF-16 | O cardápio público deve refletir a identidade visual do estabelecimento (logo, nome), dentro do padrão entregue na implantação. |
| RF-45 | O Admin deve poder criar, editar e excluir adicionais reutilizáveis, com nome, descrição, preço base e controle de estoque próprio (RN-45, RN-47). |
| RF-46 | O Admin deve poder anexar um ou mais adicionais a um produto, opcionalmente sobrescrevendo o preço e/ou definindo uma quantidade máxima por pedido para aquele vínculo (RN-46). |
| RF-47 | O cardápio público e o painel de atendimento devem permitir ao cliente/atendente escolher, por adicional selecionado, a quantidade e — quando o produto é um combo de sabores — se ele se aplica ao produto inteiro ou a um sabor específico (RN-48). |
| RF-48 | O sistema deve recalcular no servidor o preço e o consumo de estoque de cada adicional selecionado no checkout, nunca aceitando esses valores do navegador (RN-49). |

### 7.3 Módulo: Pedidos Online / Carrinho

| ID | Requisito |
|---|---|
| RF-17 | O Cliente Final deve poder adicionar itens ao carrinho, incluindo sabores/combos, e revisar o pedido antes de confirmar. |
| RF-18 | No checkout, o sistema busca automaticamente um cliente existente pelo telefone informado dentro do tenant, ou cria um cadastro novo (RN-01). Quando o tenant exige CPF (RN-52), o checkout online mostra o campo de CPF (com máscara, gravado só com dígitos) e o valida antes de finalizar; se o cliente já tem CPF cadastrado, ele vem pré-preenchido. |
| RF-19 | O sistema deve calcular o total do pedido no servidor (nunca no navegador), incluindo descontos e taxa de entrega. Havendo uma única forma de pagamento, o campo de valor já vem preenchido com o total do pedido (acompanha mudanças de entrega/endereço até o cliente editar). |
| RF-20 | O Admin deve poder configurar opções de entrega (nome, tipo — retirada ou entrega —, valor mínimo de pedido para isenção), por tenant. Para retirada, a taxa é fixa (RN-30); para entrega, a taxa vem dos setores configurados (RF-36). |
| RF-21 | O checkout deve bloquear pedidos fora do horário de funcionamento do tenant, informando o próximo horário de abertura (RN-23). |
| RF-22 | Ao confirmar, o sistema registra o pedido, gera mensagem estruturada e redireciona ao WhatsApp configurado para aquele tenant (RN-26, RN-27). |
| RF-23 | O sistema deve gerar uma página de acompanhamento do pedido em tempo real (RN-28). |
| RF-24 | Pagamento online não está no escopo padrão atual; a forma de pagamento é apenas informada/registrada (com campo de troco), e o pagamento é tratado fora do sistema. |
| RF-35 | O checkout do carrinho deve ter um campo de CEP que, ao ser preenchido, busca automaticamente o endereço via ViaCEP e preenche logradouro, bairro, cidade e UF, permitindo edição manual de qualquer campo preenchido (RN-33). |
| RF-36 | O Admin deve poder cadastrar setores de entrega por tenant (nome, taxa) e associar uma lista de bairros a cada setor (RN-34, RN-35). |
| RF-37 | O Admin deve poder configurar, por tenant, se atende bairros não cadastrados em nenhum setor e, se sim, a taxa específica aplicada nesse caso (RN-36). |
| RF-38 | O sistema deve resolver a taxa de entrega no servidor a partir do bairro do cliente, seguindo a ordem de resolução da RN-37, inclusive bloqueando o checkout quando aplicável. |
| RF-39 | O painel deve sinalizar visualmente pedidos com taxa de bairro não configurado, para que o atendente confirme a viabilidade da entrega antes de despachar. |

### 7.3.1 Módulo: Catálogo de Features e Planos (painel central)

| ID | Requisito |
|---|---|
| RF-40 | O painel central deve permitir à equipe Razel Tec criar/editar planos, compondo cada plano a partir do catálogo de features disponível (RN-40). |
| RF-41 | O painel central deve permitir atribuir um plano a um tenant e, opcionalmente, aplicar overrides pontuais de feature para aquele tenant (RN-41). |
| RF-42 | O painel do tenant deve esconder do menu de navegação qualquer recurso cuja feature não esteja habilitada para aquele tenant (RN-43). |
| RF-43 | O sistema deve bloquear o acesso direto (rota/página) a qualquer recurso do painel cuja feature não esteja habilitada para o tenant, mesmo com a URL correta, reavaliando a cada requisição (RN-43). |
| RF-44 | O catálogo de features deve suportar entradas reservadas (ainda sem implementação funcional) sem que apareçam como habilitadas em nenhum plano/tenant (RN-42) — usado hoje para PDV, controle de estoque e NF-e (emissão/entrada). |

### 7.4 Módulo: Gestão da Cozinha e Pedidos

| ID | Requisito |
|---|---|
| RF-25 | Deve existir uma tela dedicada à cozinha, por tenant, para visualizar pedidos e avançar cada etapa (seção 6.1). |
| RF-26 | O painel deve permitir aceitar, rejeitar, avançar ou cancelar um pedido, com motivo categorizado obrigatório no cancelamento (RN-31). |
| RF-27 | O sistema deve registrar data/hora de cada transição de status do pedido. |
| RF-28 | Deve existir um fluxo específico para o entregador confirmar a entrega, sem exigir login completo (ex.: link assinado/QR code). |
| RF-29 | O painel deve listar o histórico de pedidos do tenant, com filtros por status, período e origem (cardápio, atendente, mesa). |

### 7.5 Módulo: Painel Administrativo (Filament)

| ID | Requisito |
|---|---|
| RF-30 | O painel deve concentrar, sempre restrito ao próprio tenant: cardápio, pedidos, clientes, configurações do estabelecimento e usuários/permissões. O cliente exibe/edita o CPF quando informado (RN-52). |
| RF-31 | O painel deve ter dashboard com indicadores operacionais (pedidos por status/dia/hora/origem, formas de pagamento, motivos de cancelamento, mais vendidos por período) do próprio tenant. |
| RF-32 | O painel deve ser utilizável em desktop e tablet, comum no balcão/cozinha. |
| RF-33 | Deve existir controle de acesso por perfil (Admin, Gerente, Atendente, Caixa, Entregador), sempre avaliado dentro do tenant do usuário logado. |
| RF-34 | O painel deve permitir upload e troca do logo do estabelecimento, refletido no cardápio público daquele tenant. |

### 7.6 Módulo: Painel Central — Operação (Razel Tec)

| ID | Requisito |
|---|---|
| RF-52 | O painel central deve permitir **exportar** o catálogo global de localidades (estados, cidades, bairros) para um arquivo e **importá-lo** em outro ambiente, agrupado por chave natural (UF, código IBGE, nome normalizado), de forma idempotente (insere/atualiza, nunca apaga) — evita refazer em produção uma sincronização lenta já feita em outro ambiente. |
| RF-53 | Na lista de tenants do painel central, a equipe Razel Tec (perfil Plataforma) deve poder abrir o painel de um tenant específico (`/painel/{slug}`) em uma nova aba, direto da linha do tenant. |

---

## 8. Requisitos Não-Funcionais

| ID | Requisito |
|---|---|
| RNF-01 | **Isolamento de dados:** cada tenant deve ser isolado logicamente por um identificador de tenant presente em toda tabela de domínio, aplicado automaticamente por escopo global em todas as consultas — nunca dependendo de o desenvolvedor lembrar de filtrar manualmente. |
| RNF-02 | **Roteamento por path (histórico: subdomínio):** a resolução do tenant a partir do slug na URL deve ter overhead desprezível e falhar de forma segura (nunca expor dados de outro tenant) caso o slug não seja reconhecido. |
| RNF-03 | **Desempenho:** o cardápio público deve carregar em poucos segundos mesmo em conexão móvel 3G/4G. |
| RNF-04 | **Disponibilidade:** o cardápio público é a vitrine do restaurante — alta disponibilidade, especialmente em horário de pico (almoço/jantar), para todos os tenants simultaneamente. |
| RNF-05 | **Simplicidade de uso:** qualquer tela do painel deve ser operável sem familiaridade técnica nem treinamento formal extenso. |
| RNF-06 | **Consistência visual:** identidade RazelTec (dark mode, gradientes azul/violeta, Space Grotesk/Inter/JetBrains Mono) no painel administrativo, e marca própria RazelFood (`razelfood.com.br`) nas telas voltadas ao cliente final. |
| RNF-07 | **Segurança:** preço sempre resolvido no servidor (RN-13); dados de clientes finais tratados conforme LGPD, com coleta mínima necessária; certificado wildcard válido para `*.razelfood.com.br`. |
| RNF-08 | **Concorrência:** operações sensíveis (ex.: baixa de saldo de promoção relâmpago) devem ser transacionais, evitando overselling em picos, sempre dentro do escopo do tenant (RN-22). |
| RNF-09 | **Padrões de código:** skill `laravel-dev` (Laravel 12 + Blade + Tailwind v4 + Livewire 3 + Alpine.js 3 + Filament 4 + MySQL). |

---

## 9. Escopo da Proposta Comercial

Conforme a Proposta Comercial de 18/08/2026, válida como referência do pacote padrão vendido hoje:

**Incluso:**
- Cardápio online completo (categorias, produtos, imagens, promoções, ofertas relâmpago, destaque de mais vendidos, adicionais de produto).
- Recebimento de pedidos via carrinho + redirecionamento estruturado ao WhatsApp.
- Gestão da cozinha com fluxo de status.
- Administração completa do cardápio, pedidos, horários de funcionamento.
- Hospedagem, infraestrutura, manutenção e atualizações técnicas pela Razel Tec.

**Não incluso no pacote padrão** (pode ser orçado/roadmapeado separadamente):
- Funcionalidades personalizadas não previstas na proposta.
- Integrações com sistemas externos não especificadas (ex.: emissão fiscal/NFe, PDV de caixa, controle de estoque avançado, financeiro completo). Não fazem parte do escopo do RazelFood hoje; podem ser avaliadas no futuro, inspiradas nos módulos equivalentes do Pizzaria-App. **Reservados como features indisponíveis no catálogo de planos (RN-42, seção 4.1)** — a existência da reserva no catálogo não antecipa a implementação funcional desses módulos.
- Aplicativo mobile nativo.
- Equipamentos físicos (impressoras térmicas, computadores, tablets, celulares).
- Serviços de terceiros eventualmente necessários.
- Custos de anúncios, tráfego pago ou marketing digital.
- Pagamento online integrado (cartão/Pix automático).

**Modelo comercial resumido:** Implantação R$ 1.500,00 (pagamento único) + Mensalidade R$ 150,00/mês, recorrente enquanto o serviço estiver ativo. Sem taxas adicionais além das descritas na proposta.

---

## 10. Glossário

- **Tenant:** cliente/estabelecimento dentro da aplicação multi-tenant RazelFood, com seus próprios dados isolados logicamente.
- **Slug:** identificador único e legível de um tenant, usado como subdomínio (ex.: `emporiodapizza` em `emporiodapizza.razelfood.com.br`).
- **Cardápio público:** página acessível ao Cliente Final, sem necessidade de login, servida no subdomínio do tenant.
- **Sabores/combo:** combinação de dois ou mais produtos em um único item vendido (ex.: pizza meio a meio), com preço rateado.
- **Adicional:** item opcional vendido junto de um produto (ex.: porção extra de bacon), com preço base e estoque próprios, anexado a um ou mais produtos específicos; seu custo e consumo de estoque são rateados pela mesma fração de sabor do produto ao qual foi aplicado (RN-45, RN-48).
- **Promoção relâmpago:** oferta por tempo/quantidade limitada, pontual ou recorrente, com teto de unidades e limite por pedido.
- **Saldo (de uma promoção):** unidades ainda disponíveis dentro do teto configurado.
- **Origem do pedido:** de onde o pedido partiu — cardápio online, atendente (lançado manualmente) ou mesa.
- **Motivo de cancelamento:** categoria obrigatória registrada ao cancelar um pedido, usada em relatórios.
- **Setor de entrega:** agrupamento de bairros definido pelo tenant, com taxa de entrega própria (RN-34).
- **Bairro não configurado:** bairro do cliente que não está associado a nenhum setor cadastrado pelo tenant (RN-36, RN-37).
- **Feature:** funcionalidade individual do sistema, identificada por uma chave técnica estável, que pode estar disponível ou reservada no catálogo (RN-39, RN-42).
- **Plano:** conjunto nomeado de features, atribuído a um tenant pela Razel Tec (RN-40).
- **Override de feature:** exceção pontual aplicada a um tenant específico, ligando ou desligando uma feature independente do que o plano dele define (RN-41).

---

## 11. Itens em Aberto / Próximos Passos

1. **Estratégia de isolamento de dados:** confirmar e documentar formalmente a abordagem técnica — banco único compartilhado com identificador de tenant + escopo global (recomendado, menor custo operacional) versus banco por tenant (maior isolamento físico, maior custo de operação). Decisão a tomar antes do início do desenvolvimento.
2. **Tratamento do primeiro cliente já fechado:** decidir se o cliente já vendido sob a proposta comercial atual entra na nova arquitetura multi-tenant desde o início do desenvolvimento, ou se é implantado separadamente e migrado depois.
3. **Política de slug:** formalizar regras de formatação, lista de palavras reservadas, processo de troca (RN-05), e o que fazer quando dois clientes disputam o mesmo nome comercial (ex.: duas "Pizzaria Bella" em cidades diferentes).
4. **Onboarding self-service:** hoje o provisionamento de um tenant é assistido pela equipe da Razel Tec (RN-09). Avaliar se/quando abrir um fluxo de contratação com provisionamento automático de tenant.
5. **Domínio próprio por cliente (CNAME):** confirmar se entra como diferencial pago já no lançamento ou fica para uma fase 2 (RN-06).
6. ~~Planos, upgrade/downgrade e trial~~ — **decidido em 20/08/2026**: a infraestrutura de planos (catálogo de features + planos + overrides por tenant) entra em desenvolvimento, ver seção 4.1 (RN-39 a RN-44). O modelo de **preço** continua sem tiers definidos (RN-08) até decisão de diretoria — o mecanismo técnico de plano/feature nasce desacoplado de precificação. Trial segue fora de escopo, não foi decidido.
7. **Retenção de dados pós-cancelamento:** confirmar prazo de retenção/exportação de dados de um tenant quando o cliente encerra o contrato.
8. **Página de acompanhamento em tempo real e link de avaliação pós-pedido:** como o RazelFood é construído do zero, essas funcionalidades (existentes no sistema de referência) não vêm "de graça" — confirmar se entram no escopo padrão da primeira versão ou ficam para depois.
9. **Pagamento online integrado:** confirmar se entra no roadmap de curto prazo como upsell ou fica de fora por ora.
10. **Impressão de pedidos (comanda/cozinha):** equipamento é do cliente; validar se a integração de impressão é necessária já no início.
11. ~~Módulos avançados do sistema de referência (caixa, estoque, fiscal)~~ — **decidido em 20/08/2026**: entram no catálogo de features (RN-39) como reservas de roadmap (PDV, estoque, NF-e emissão/entrada), marcadas `is_available = false`, sem implementação funcional nesta fase — ver seção 4.1. A implementação real de cada módulo continua sendo avaliada e priorizada separadamente, item por item, quando entrar em desenvolvimento.
12. ~~Busca de CEP no cadastro de cliente do painel~~ — **resolvido**: o `ClientForm` do painel do tenant tem o mesmo campo de CEP com busca via `ViaCepClient` (`App\Filament\Tenant\Resources\Clients\Schemas\ClientForm`). O formulário de cliente também passou a ter o campo de **CPF** (RN-52), com máscara e gravação só de dígitos.
13. ~~Normalização de nomes de bairro~~ — **resolvido em 20/08/2026**: normalização por acento/maiúscula (`App\Support\NeighborhoodNormalizer`) aplicada na gravação e na busca, ver `modelagem-middleware-multitenant.md` seção 3.6.
14. **Indisponibilidade do ViaCEP:** RN-33 já prevê preenchimento manual como fallback quando o serviço externo falha ou não encontra o CEP; definir um timeout máximo aceitável para a chamada, para não travar o checkout.

> **Recomendação:** os itens 1, 2 e 3 são pré-requisitos técnicos para começar a desenvolver — valem uma decisão rápida com o time antes de qualquer linha de código. Os itens 4, 5 e 6 podem esperar uma segunda rodada, depois que o modelo multi-tenant estiver rodando com os primeiros clientes.

---

*Documento atualizado com a confirmação da arquitetura multi-tenant e do domínio `razelfood.com.br`. Deve ser revisado e aprovado antes de virar referência oficial para desenvolvimento.*