# RazelFood — Modelagem de Dados e Middleware Multi-tenant

**Documento de instrução para implementação e homologação**
**Versão:** 2.1
**Data:** 01/09/2026
**Depende de:** `requisitos-regras-negocio.md` (v3.4) — este documento traduz as regras RN-02 a RN-52 daquele documento em schema de banco, middleware e estrutura de rotas concretos.

> **Nota de versão (2.0 → 2.1) — set de features de ago/2026:**
> - `categories`: `description` (text, nullable), `show_description_in_menu` (bool, default false) — descrição opcional exibida abaixo do nome da categoria/subcategoria no cardápio (RN-50); `inherit_flavor_options` (bool, default false) — subcategoria herda as `flavor_quantity_options` da categoria pai em vez de cadastrar as suas (RN-51). Fonte única de leitura: `Category::resolvedFlavorQuantityOptions()`.
> - `clients`: `cpf` (string(11), nullable, **só dígitos**, sem `unique`) — CPF do cliente (RN-52). Validado por `App\Rules\ValidCpf` (mesmo molde de `ValidCnpj`).
> - `tenants`: `require_client_cpf` (bool, default false) — exige CPF no checkout online público (RN-52); não afeta a Central de Pedidos.
> - Painel: subcategoria ganhou página de edição completa do Resource (aba "Quantidades de sabores"); bulk actions em produtos — "Replicar para outra categoria" e "Ajustar preço" (RF-49, RF-50); anexar múltiplos adicionais de uma vez e cadastrar bairros de setor em lote (RF-51); painel central — export/import do catálogo de localidades e ação "Acessar painel" na lista de tenants (RF-52, RF-53).
> - Sem impacto de schema: pré-preenchimento da 1ª forma de pagamento com o total no checkout; cabeçalho e barra de categorias fixos (`sticky`) no cardápio web.
>
> **Nota:** o bloco de schema de `tenants` na seção 3.1 está defasado — não reflete
> colunas operacionais adicionadas em sessões anteriores (SLA de pedido em minutos,
> flags de fluxo de entrega `uses_in_transit_stage`/`assigns_delivery_couriers`,
> logo de impressão, favicon, `orders_sequence`, `watermark_height`). O model
> `App\Models\Tenant` (`$fillable` + `casts()`) é a fonte de verdade.

> **Nota de versão (1.3 → 2.0) — TENANCY POR PATH:** o tenant deixou de ser
> identificado por **subdomínio** (`{slug}.razelfood.com.br`) e passou a ser
> identificado por **path**, num domínio único:
> - Cardápio público: `razelfood.com.br/{slug}/...` — grupo `Route::prefix('{tenant}')`
>   com o middleware `App\Http\Middleware\ResolveTenantFromPath` (não é mais
>   middleware global; `IdentifyTenant` foi renomeado e reescrito).
> - Painel do tenant: `razelfood.com.br/painel/{slug}` — **tenancy nativa do
>   Filament** (`->tenant(Tenant::class, slugAttribute: 'slug')`), com
>   `User implements HasTenants` (`getTenants()` / `canAccessTenant()`) e o
>   middleware persistente `App\Http\Middleware\ApplyTenantScopes` fazendo a
>   ponte `Filament::getTenant()` → `CurrentTenant`/`TenantScope`/spatie-teams.
> - Painel central: `razelfood.com.br/admin` (era `interno.razelfood.com.br/central`).
>
> Motivação: eliminar a dependência de wildcard DNS + wildcard SSL na HostGator
> (era o maior risco técnico). Provisionar um tenant novo passou a ser um
> `INSERT` em `tenants`, sem configuração de infra. **Nenhuma migration de
> banco** — `tenants.slug` já era a chave e é domain-agnostic. Cutover em
> produção: 31/08/2026.
>
> **As seções 4 (Middleware e Resolução de Tenant) e 9 (Hospedagem) abaixo
> foram reescritas para o modelo por path.** Guia operacional detalhado (e
> armadilhas) em `.ai/rules/{middleware,routes,resources,users,models-filament,app-livewire,scripts}.md`.

> **Nota de versão (1.2 → 1.3):** adicionadas as tabelas `addons` e `product_addon`, e as colunas `addons`/`addons_total` em `order_items` — ver seção 3.3 (nova subseção `addons`/`product_addon`, e `orders`/`order_items` atualizada). Reflete RN-45 a RN-49 de `requisitos-regras-negocio.md` v3.3. Aproveitado pra documentar também `flavor_quantity_options.flavor_shares` (coluna adicionada em 21-22/08/2026, ainda não refletida na v1.2) e corrigir o tipo de `products.sales_count` (documentado como `unsignedInteger`, migração real já é `decimal(10,2)` desde a correção de 22/08/2026) — ambos descobertos durante esta mesma revisão de schema.

> **Nota de versão (1.1 → 1.2):** adicionadas as tabelas `features`, `plans`, `plan_feature` e `tenant_feature_overrides`, a coluna `tenants.plan_id`, e o padrão de reforço de acesso por feature no Filament (`canAccess()` + `shouldRegisterNavigation()`) — ver seções 3.1.1 (nova) e 4.9 (nova). Reflete RN-39 a RN-44 de `requisitos-regras-negocio.md` v3.2.

> **Nota de versão (1.0 → 1.1):** adicionadas as tabelas `delivery_zones` e `delivery_zone_neighborhoods`, os campos de setor/bairro não configurado em `tenants`, os campos estruturados de endereço em `clients`/`orders`, e o serviço de busca de CEP (ViaCEP) — ver seções 3.3 (atualizada), 3.5 e 3.6. Reflete RN-33 a RN-38 de `requisitos-regras-negocio.md` v3.1.

---

## 0. Como usar este documento

Este documento existe para que a implementação (e a homologação) do RazelFood não perca o escopo definido no levantamento de regras de negócio. Ele é deliberadamente concreto — schema de migration, nome de classe, trecho de código — para poder ser colado direto num prompt de instrução para quem for codar (humano ou agente de IA), sem depender de memória de conversa anterior.

**Guardrails (não fazer, mesmo que pareça natural durante a implementação):**
- Não criar tabela `empresas` única nem qualquer variação de "instância por cliente" — isso foi decidido e revertido (ver nota de versão 2.0→3.0 do documento de regras de negócio). O RazelFood é multi-tenant com banco único compartilhado.
- Não implementar módulos de caixa, estoque avançado, fiscal/NFe ou financeiro completo "porque já existe no Pizzaria-App". Esses módulos continuam fora do escopo atual (seção 9 do documento de regras de negócio) — construir aqui é regressão de escopo, não reaproveitamento. **Exceção explícita:** o catálogo de features (seção 3.1.1) pode e deve conter uma entrada *reservada* (`is_available = false`) para PDV, estoque e NF-e — isso é só um placeholder de roadmap, não a implementação do módulo.
- Não introduzir trial ou cobrança pró-rata automatizada — não existem no modelo comercial vigente (RN-08). A infraestrutura de planos/features (catálogo + atribuição por tenant, RN-39 a RN-44) **entra em escopo agora**, mas nasce desacoplada de precificação — não implica em criar tiers de preço sem decisão de diretoria.
- Não deixar nenhuma tabela de domínio sem `tenant_id` — isso é o requisito não-negociável desta arquitetura (RNF-01).

---

## 1. Decisão de Nomenclatura — **Confirmada**

O `Pizzaria-App` (sistema de referência) usa colunas prefixadas em português (`produto_descricao`, `categoria_nome`, `pedido_status`). Como o RazelFood é escrito do zero, a skill `laravel-dev` do time recomenda nomes técnicos (classes, tabelas, colunas) em **inglês**, com **strings voltadas ao usuário em pt-BR**. Este documento segue essa convenção — schema e código em inglês, comentários e labels em português.

> **Confirmado com o time em 19/08/2026:** nomenclatura técnica em inglês, conforme especificado neste documento. Não revisitar essa decisão sem motivo novo.

---

## 2. Arquitetura Multi-tenant — Abordagem Escolhida

**Recomendação: banco de dados único compartilhado, com coluna `tenant_id` em toda tabela de domínio + Global Scope automático.** Não banco-por-tenant.

Por quê:
- Menor custo operacional (uma migration roda para todos os tenants de uma vez; um backup cobre todo mundo; um deploy atualiza todo mundo).
- Compatível com a motivação original da mudança de arquitetura (RN-07 do documento de regras: reduzir custo marginal de suporte por cliente novo).
- Continua permitindo migrar tenants grandes para isolamento físico depois, se algum cliente exigir — não é uma decisão irreversível.

**Implementação: solução própria e enxuta, não um pacote de tenancy pronto (ex.: `stancl/tenancy`).** Como o RazelFood é multi-tenant de banco único, um trait + global scope (`BelongsToTenant`/`TenantScope`) + middleware, escritos à mão, cobrem o cardápio público. O painel do tenant, por ser Filament, adotou a **tenancy nativa do Filament** (`->tenant()`) — que também é scope-based e roda por cima do mesmo `TenantScope`; ver seção 4. Nenhum pacote de tenancy de terceiros.

---

## 3. Modelagem de Dados

### 3.1 Tabela central: `tenants`

Não tem `tenant_id` (é a própria definição de tenant). Fora do escopo do Global Scope.

```php
Schema::create('tenants', function (Blueprint $table) {
    $table->id();
    $table->string('name');                       // Nome comercial do estabelecimento
    $table->string('slug')->unique();              // Identifica o tenant no path: razelfood.com.br/{slug} (cardápio) e /painel/{slug} (painel) — ver nota de versão 1.3→2.0
    $table->enum('status', ['active', 'suspended', 'cancelled'])->default('active');
    $table->string('whatsapp_number', 20);          // Número que recebe os pedidos (RN-27)
    $table->string('logo_path')->nullable();
    $table->string('primary_color', 7)->nullable(); // Cor de destaque do cardápio (RF-16)
    $table->boolean('recaptcha_enabled')->default(false); // RN-29
    $table->string('recaptcha_site_key')->nullable();
    $table->string('recaptcha_secret_key')->nullable();
    $table->boolean('serves_unlisted_neighborhoods')->default(false); // RN-36
    $table->decimal('unlisted_neighborhood_fee', 10, 2)->nullable();  // RN-36, obrigatório quando serves_unlisted_neighborhoods = true (validado na camada de aplicação, não no schema)
    $table->boolean('require_client_cpf')->default(false); // RN-52: exige CPF no checkout online público (não afeta a Central de Pedidos)
    $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete(); // RN-40, ver seção 3.1.1
    $table->timestamps();
    $table->softDeletes(); // Retenção pós-cancelamento (item em aberto #7 do doc de regras)

    $table->index('status');
});
```

`slug`: validado contra a lista de reservados (seção 4.2) na criação, nunca editável por rota pública — só via ferramenta interna da Razel Tec (RF-03), e mesmo assim como operação assistida (RN-05).

### 3.1.1 Catálogo de Features e Planos

Implementa RN-39 a RN-44. `features`, `plans` e `plan_feature` são **tabelas centrais**, fora do `TenantScope` — mesma categoria de `tenants`, administradas só pelo painel central da Razel Tec. `tenant_feature_overrides` referencia um `tenant_id`, mas também é gerida exclusivamente do painel central (nunca pelo próprio tenant, RN-44) — por isso não usa a trait `BelongsToTenant` (seção 4.4): ela precisa ser lida/gravada mesmo fora do contexto de requisição daquele tenant.

```php
Schema::create('features', function (Blueprint $table) {
    $table->id();
    $table->string('key')->unique();     // identificador técnico estável, usado no código: Tenant::hasFeature('pdv')
    $table->string('name');               // rótulo exibido no painel central
    $table->text('description')->nullable();
    $table->boolean('is_available')->default(true); // false = reservado no catálogo, sem implementação funcional (RN-42)
    $table->unsignedInteger('display_order')->default(0);
    $table->timestamps();
});

Schema::create('plans', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->text('description')->nullable();
    $table->boolean('is_active')->default(true);
    $table->unsignedInteger('display_order')->default(0);
    $table->timestamps();
});

Schema::create('plan_feature', function (Blueprint $table) {
    $table->id();
    $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
    $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
    $table->timestamps();

    $table->unique(['plan_id', 'feature_id']);
});

Schema::create('tenant_feature_overrides', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
    $table->boolean('enabled'); // true = força ligado mesmo fora do plano; false = força desligado mesmo dentro do plano (RN-41)
    $table->timestamps();

    $table->unique(['tenant_id', 'feature_id']);
});
```

Seed inicial do catálogo (feature `is_available`, RN-42), em `App\Support\FeatureKey`: `cardapio_digital`, `configuracoes_estabelecimento`, `central_de_pedidos`, `historico_pedidos`, `configuracoes_pedidos`, `linhas_producao` e `usuarios_permissoes` nascem `true` (já implementadas — todas entram no plano Essencial, RN-40, por serem operação básica, não upsell); `pdv`, `estoque`, `nfe_emissao` e `nfe_entrada` nascem `false` (reservadas, sem código por trás ainda).

**Resolução da feature efetiva de um tenant** (`App\Models\Tenant::hasFeature()`), aplicando RN-41 e RN-42 nessa ordem — override vence o plano, e uma feature indisponível nunca é liberada:

```php
// app/Models/Tenant.php (trecho)
public function hasFeature(string $key): bool
{
    $feature = app(FeatureCatalog::class)->findByKey($key); // cacheado em memória por request, ver seção 4.8

    if (! $feature || ! $feature->is_available) {
        return false;
    }

    $override = $this->featureOverrides->firstWhere('feature_id', $feature->id);
    if ($override) {
        return $override->enabled;
    }

    return $this->plan?->features->contains('id', $feature->id) ?? false;
}
```

> Carregar `plan.features` e `featureOverrides` via eager load no middleware `ResolveTenantFromPath` (seção 4.3), junto da resolução do tenant — evita N+1 quando `hasFeature()` é chamado várias vezes por request (nav do Filament chama isso por recurso).

### 3.2 Usuários e Permissões

Usuário pertence a **no máximo um** tenant. Usuário da equipe Razel Tec (acesso multi-tenant) tem `tenant_id` nulo.

```php
Schema::table('users', function (Blueprint $table) {
    $table->foreignId('tenant_id')->nullable()->after('id')
        ->constrained()->cascadeOnDelete();

    $table->index('tenant_id');
});
```

Papéis (Admin, Gerente, Atendente, Caixa, Entregador — seção 3 do doc de regras) via `spatie/laravel-permission`. Usar o **recurso de "teams"** do pacote, apontando para `tenant_id` em vez do `team_id` padrão:

```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

```php
// config/permission.php
'teams' => true,

'column_names' => [
    // ...
    'team_foreign_key' => 'tenant_id',
],
```

O pacote resolve nativamente "essa role vale só dentro deste tenant" — mas **não** descobre o tenant sozinho: é preciso chamar `PermissionRegistrar::setPermissionsTeamId()` a cada requisição, o que já está incluído no middleware `ResolveTenantFromPath` (cardápio) e `ApplyTenantScopes` (painel do tenant).

### 3.3 Tabelas de Domínio (todas com `tenant_id`)

Convenção repetida em toda tabela desta seção:

```php
$table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
$table->index('tenant_id'); // ou índice composto quando indicado
```

#### `categories`

```php
Schema::create('categories', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
    $table->string('name');
    $table->text('description')->nullable();               // RN-50: exibida no cardápio se show_description_in_menu = true
    $table->unsignedInteger('display_order')->default(0);
    $table->boolean('show_in_menu')->default(true);
    $table->boolean('show_description_in_menu')->default(false); // RN-50
    $table->boolean('allows_flavors')->default(false);  // "meio a meio" (RN-16)
    $table->boolean('inherit_flavor_options')->default(false);  // RN-51: subcategoria usa as flavor_quantity_options do pai
    $table->timestamps();
    $table->softDeletes();

    $table->index(['tenant_id', 'parent_id']);
    $table->index(['tenant_id', 'show_in_menu']);
});
```

> **Hierarquia de categorias (implementação, ago/2026):** 1 nível só — categoria
> raiz → subcategoria. Subcategoria é editada numa **página completa do Resource**
> (`EditAction->url(CategoryResource::getUrl('edit'))` no `SubcategoriesRelationManager`);
> o filtro `whereNull('parent_id')` vive em `CategoriesTable::modifyQueryUsing()`
> (só a listagem), não em `getEloquentQuery()`. `SubcategoriesRelationManager::canViewForRecord()`
> retorna `parent_id === null` (trava o aninhamento). Ver `.ai/rules/livewire-orders.md`.

#### `flavor_quantity_options`

Quantidades de sabores que o cliente pode escolher para produtos de uma categoria com `allows_flavors=true` — definidas pelo próprio tenant (RN-16), não uma lista fixa do sistema. Ex.: `{label: "Sabor único", flavor_count: 1}`, `{label: "Meio a meio", flavor_count: 2}`.

```php
Schema::create('flavor_quantity_options', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('category_id')->constrained()->cascadeOnDelete();
    $table->string('label');
    $table->unsignedTinyInteger('flavor_count');
    $table->json('flavor_shares')->nullable(); // % (0-100) por posição de sabor, soma sempre 100 — rateio de estoque/preço de combo (RN-16) E de adicionais (RN-48); null = FlavorQuantityOption::equalShares() como fallback (divisão igualitária com o resto no último sabor)
    $table->unsignedInteger('display_order')->default(0);
    $table->timestamps();
    $table->softDeletes();

    $table->index(['tenant_id', 'category_id']);
    $table->unique(['category_id', 'flavor_count']);
});
```

> **Herança pai→subcategoria (RN-51, ago/2026):** as opções pertencem a UMA
> categoria (`category_id`), sem herança no schema. `Category::resolvedFlavorQuantityOptions()`
> é a **fonte única** de leitura no cardápio/checkout/PDV: quando a subcategoria
> tem `inherit_flavor_options = true`, retorna `parent->flavorQuantityOptions`,
> senão as próprias. Nunca ler `->flavorQuantityOptions` direto para lógica de
> combo. `ResolvePriceForCartLine::resolveCombo`, `Menu::menuCategory()` e
> `FlavorPickerModal` já usam o helper. Ver `.ai/rules/livewire-orders.md`.

#### `products`

```php
Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('category_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->text('description')->nullable();
    $table->string('image_path')->nullable();
    $table->decimal('price', 10, 2);
    $table->decimal('promotional_price', 10, 2)->nullable();  // RN-14 (precedência #2)
    $table->timestamp('promo_starts_at')->nullable();
    $table->timestamp('promo_ends_at')->nullable();
    $table->boolean('is_visible')->default(true);              // RN-11
    $table->boolean('controls_stock')->default(false);         // RN-24
    $table->decimal('stock_quantity', 10, 2)->nullable();
    $table->boolean('show_when_out_of_stock')->default(false); // RN-11
    $table->boolean('bestseller_eligible')->default(false);    // RN-15
    $table->decimal('sales_count', 10, 2)->unsigned()->default(0); // RN-15 — decimal (não inteiro) porque combo/adicional soma fração, não unidade cheia (RN-16, RN-48)
    $table->unsignedInteger('display_order')->default(0);
    $table->timestamps();
    $table->softDeletes();

    $table->index(['tenant_id', 'is_visible']);
    $table->index(['tenant_id', 'bestseller_eligible', 'sales_count']);
});
```

#### `addons` e `product_addon`

Adicionais reutilizáveis do tenant (RN-45), com controle de estoque próprio (RN-47) — mesmas colunas/semântica de `products.controls_stock`/`stock_quantity`/`show_when_out_of_stock`/`sales_count`. `product_addon` é o vínculo entre um adicional e um produto específico (RN-46): decide disponibilidade, com override de preço opcional e teto de quantidade opcional por linha de carrinho.

```php
Schema::create('addons', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->text('description')->nullable();
    $table->decimal('price', 10, 2);                            // preço base
    $table->boolean('controls_stock')->default(false);
    $table->decimal('stock_quantity', 10, 2)->nullable();
    $table->boolean('show_when_out_of_stock')->default(false);
    $table->decimal('sales_count', 10, 2)->unsigned()->default(0); // fracionário — mesmo rateio de flavor_shares (RN-48)
    $table->unsignedInteger('display_order')->default(0);
    $table->timestamps();
    $table->softDeletes();

    $table->index(['tenant_id']);
});

Schema::create('product_addon', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('product_id')->constrained()->cascadeOnDelete();
    $table->foreignId('addon_id')->constrained()->cascadeOnDelete();
    $table->decimal('price', 10, 2)->nullable();          // override; null = usa addons.price (RN-46)
    $table->unsignedInteger('max_quantity')->nullable();  // teto de porções por linha; null = sem limite

    $table->index('tenant_id');
    $table->unique(['product_id', 'addon_id']);
});
```

#### `flash_promotions` e `flash_promotion_products`

```php
Schema::create('flash_promotions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->text('description')->nullable();
    $table->boolean('is_active')->default(true);
    $table->boolean('is_recurring')->default(false);           // RN-17
    $table->timestamp('starts_at')->nullable();
    $table->timestamp('ends_at')->nullable();
    $table->json('weekdays')->nullable();                      // [0..6], vazio = todos os dias
    $table->time('start_time')->nullable();                    // suporta janela que cruza meia-noite
    $table->time('end_time')->nullable();
    $table->date('recurrence_end_date')->nullable();
    $table->timestamp('last_reset_at')->nullable();
    $table->unsignedInteger('total_quantity')->nullable();     // pool; null = sem teto (RN-18)
    $table->decimal('sold_quantity', 10, 2)->default(0);
    $table->unsignedInteger('per_order_limit')->nullable();    // RN-19
    $table->boolean('show_counter')->default(false);           // RN-20
    $table->unsignedInteger('scarcity_threshold')->nullable();
    $table->boolean('allows_flavors')->default(false);
    $table->unsignedTinyInteger('max_flavors')->nullable();
    $table->unsignedInteger('display_order')->default(0);
    $table->timestamps();
    $table->softDeletes();

    $table->index(['tenant_id', 'is_active']);
});

Schema::create('flash_promotion_products', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); // confirmado: entra no isolamento multi-tenant como toda tabela de domínio
    $table->foreignId('flash_promotion_id')->constrained()->cascadeOnDelete();
    $table->foreignId('product_id')->constrained()->cascadeOnDelete();
    $table->decimal('promotional_price', 10, 2);
    $table->unsignedInteger('total_quantity')->nullable();   // sub-limite por produto dentro da promo
    $table->decimal('sold_quantity', 10, 2)->default(0);

    $table->index('tenant_id');
    $table->unique(['flash_promotion_id', 'product_id']);
});
```

#### `clients`

```php
Schema::create('clients', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('phone', 20);         // RN-01: busca/cria por telefone
    $table->string('cpf', 11)->nullable(); // RN-52: só os 11 dígitos (sem máscara), sem unique — FindOrCreateClient casa por telefone, não por CPF

    // Endereço estruturado (RN-33) — preenchido via busca de CEP (ViaCEP) ou manualmente.
    // `neighborhood` é o campo usado para resolver o setor de entrega (RN-34, RN-37).
    $table->string('zip_code', 9)->nullable();     // CEP, formato 00000-000
    $table->string('street')->nullable();          // logradouro
    $table->string('number', 20)->nullable();
    $table->string('complement')->nullable();
    $table->string('neighborhood')->nullable();    // bairro
    $table->string('city')->nullable();
    $table->string('state', 2)->nullable();         // UF

    $table->timestamps();

    $table->unique(['tenant_id', 'phone']); // telefone único DENTRO do tenant, não globalmente
});
```

#### `business_hours`

```php
Schema::create('business_hours', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->unsignedTinyInteger('weekday'); // 0 = domingo ... 6 = sábado
    $table->time('opens_at');
    $table->time('closes_at');
    $table->boolean('is_active')->default(true);

    $table->index(['tenant_id', 'weekday', 'is_active']);
});
```

#### `delivery_options`, `delivery_zones`, `delivery_zone_neighborhoods` e `payment_options`

```php
Schema::create('delivery_options', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->decimal('delivery_fee', 10, 2)->default(0); // taxa fixa de fallback (ver nota abaixo); RN-30
    $table->decimal('min_order_for_free_delivery', 10, 2)->nullable(); // RN-30, RN-38
    $table->timestamps();
    $table->softDeletes();
});

> **Correção em relação à v1.1 original (implementação, 20/08/2026):** não existe coluna `type` em `delivery_options`. Descoberto ao implementar: a aplicação já resolve retirada vs. entrega sem ela — todo registro de `delivery_options` já representa uma modalidade de *entrega*; "retirada" é simplesmente o cliente não escolher nenhuma (`orders.delivery_option_id` fica `null`), como já em `Order::fulfillmentType()`. `delivery_options.delivery_fee` continua existindo, mas agora só como **taxa de fallback**: `ResolveDeliveryFee` (seção 3.6) usa a taxa por setor/bairro sempre que o tenant já tiver pelo menos um `delivery_zones` cadastrado; se ainda não tiver nenhum (tenant recém-criado, antes de configurar a malha de entrega), cai de volta nessa taxa fixa, para não bloquear checkout de quem ainda não migrou.

Schema::create('delivery_zones', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('name');                          // nome do setor, ex.: "Centro", "Zona Sul" (RN-34)
    $table->decimal('delivery_fee', 10, 2);           // taxa definida pelo próprio tenant
    $table->unsignedInteger('display_order')->default(0);
    $table->timestamps();
    $table->softDeletes();

    $table->index('tenant_id');
});

Schema::create('delivery_zone_neighborhoods', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('delivery_zone_id')->constrained()->cascadeOnDelete();
    $table->string('neighborhood');    // bairro; normalizado na gravação (item em aberto #13 do doc de regras)
    $table->string('city')->nullable(); // desambigua bairros de mesmo nome em cidades diferentes atendidas pelo mesmo tenant
    $table->timestamps();

    $table->index('tenant_id');
    $table->unique(['tenant_id', 'neighborhood', 'city']); // RN-35: um bairro pertence a no máximo um setor por tenant
});
// UX (ago/2026): "Adicionar bairros" no NeighborhoodsRelationManager aceita VÁRIOS
// bairros de uma vez (um registro por bairro, via CreateAction->using()) e lembra
// a última cidade usada. Ver .ai/rules/delivery-zones-relation-managers.md.

Schema::create('payment_options', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->text('description')->nullable();
    $table->boolean('show_in_menu')->default(true);
    $table->boolean('is_cash')->default(false); // controla se pede campo de troco
    $table->timestamps();
    $table->softDeletes();
});
```

#### `orders` e `order_items`

```php
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('client_id')->constrained()->restrictOnDelete();
    $table->foreignId('delivery_option_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('delivery_zone_id')->nullable()->constrained()->nullOnDelete(); // setor usado para resolver a taxa (RN-37); null se retirada, ou se bairro não configurado

    $table->decimal('items_total', 10, 2);
    $table->decimal('discount_total', 10, 2)->default(0);
    $table->decimal('delivery_fee', 10, 2)->default(0);
    $table->decimal('grand_total', 10, 2);

    $table->enum('status', [
        'started', 'open', 'preparing', 'ready', 'in_transit', 'delivered', 'finished', 'cancelled',
    ])->default('started'); // seção 6.1 do doc de regras

    $table->enum('cancellation_reason', [
        'customer_gave_up', 'entry_error', 'product_unavailable', 'delay',
        'duplicate_test', 'payment_issue', 'address_out_of_area', 'other',
    ])->nullable(); // RN-31
    $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();

    // Endereço de entrega: snapshot estruturado no momento do pedido (não FK para clients —
    // preserva histórico se o cliente atualizar o cadastro depois), mesmos campos de `clients`.
    $table->string('delivery_zip_code', 9)->nullable();
    $table->string('delivery_street')->nullable();
    $table->string('delivery_number', 20)->nullable();
    $table->string('delivery_complement')->nullable();
    $table->string('delivery_neighborhood')->nullable();
    $table->string('delivery_city')->nullable();
    $table->string('delivery_state', 2)->nullable();
    $table->boolean('is_unlisted_neighborhood')->default(false); // RN-37 caso 2 — sinaliza para o atendente confirmar viabilidade (RF-39)
    $table->string('payment_option_name')->nullable(); // snapshot do nome, não FK — preserva histórico se a opção for editada/removida depois
    $table->decimal('change_for', 10, 2)->nullable();

    $table->enum('origin', ['menu', 'staff', 'table'])->default('menu'); // origem do pedido (glossário)

    $table->timestamp('opened_at')->nullable();
    $table->timestamp('accepted_at')->nullable();
    $table->timestamp('preparing_at')->nullable();
    $table->timestamp('ready_at')->nullable();
    $table->timestamp('in_transit_at')->nullable();
    $table->timestamp('delivered_at')->nullable();
    $table->timestamp('finished_at')->nullable();
    $table->timestamp('cancelled_at')->nullable();

    $table->timestamps();

    $table->index(['tenant_id', 'status']);
    $table->index(['tenant_id', 'created_at']);
});

Schema::create('order_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->cascadeOnDelete();
    $table->foreignId('product_id')->constrained()->restrictOnDelete();
    $table->foreignId('flash_promotion_id')->nullable()->constrained()->nullOnDelete();

    $table->unsignedInteger('quantity');
    $table->decimal('unit_price', 10, 2);          // preço efetivamente cobrado (já resolvido no servidor)
    $table->decimal('original_unit_price', 10, 2); // preço de tabela, para exibir desconto
    $table->string('note')->nullable();
    $table->json('flavors')->nullable();            // IDs de produtos combinados (RN-16), null se não aplicável
    $table->json('addons')->nullable();              // [{addon_id, quantity, target}] (RN-48); nomes/preços reresolvidos ao vivo, nunca snapshotados aqui — mesmo padrão de `flavors`
    $table->decimal('addons_total', 10, 2)->default(0); // custo total dos adicionais, por unidade — mesma convenção de unit_price

    $table->timestamps();
});
```

> `order_items` não precisa de `tenant_id` próprio — sempre acessado através de `order`, que já é escopado. Evita duplicar a coluna sem necessidade.

### 3.4 Relacionamento (visão geral)

```
tenants (1) ──< users
        (1) ──< categories (1) ──< categories [self, parent_id]
        (1) ──< categories (1) ──< flavor_quantity_options
        (1) ──< products >── (N) categories
        (1) ──< addons >──< product_addon >── products
        (1) ──< flash_promotions >──< flash_promotion_products >── products
        (1) ──< clients (1) ──< orders (1) ──< order_items >── products
        (1) ──< business_hours
        (1) ──< delivery_options
        (1) ──< delivery_zones (1) ──< delivery_zone_neighborhoods
        (1) ──< payment_options
        (N) >── plans (1) ──< plan_feature >── features
        (1) ──< tenant_feature_overrides >── features
```

---

### 3.5 Serviço de busca de CEP (ViaCEP)

Implementa RN-33. Serviço fino, sem dependência externa via pacote — `Http::get()` (`Illuminate\Support\Facades\Http`) direto para `https://viacep.com.br/ws/{cep}/json/`.

```php
// app/Services/Address/ViaCepClient.php
namespace App\Services\Address;

use Illuminate\Support\Facades\Http;

class ViaCepClient
{
    /**
     * @return array{street: ?string, neighborhood: ?string, city: ?string, state: ?string}|null
     *         null quando o CEP é inválido, não é encontrado, ou o serviço externo falha/expira (RN-33: fallback é preenchimento manual, nunca bloquear o checkout por isso).
     */
    public function lookup(string $cep): ?array
    {
        $cep = preg_replace('/\D+/', '', $cep);

        if (strlen($cep) !== 8) {
            return null;
        }

        try {
            $response = Http::timeout(3)->get("https://viacep.com.br/ws/{$cep}/json/");
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return null;
        }

        if (! $response->ok() || ($response->json('erro') === true)) {
            return null;
        }

        return [
            'street' => $response->json('logradouro') ?: null,
            'neighborhood' => $response->json('bairro') ?: null,
            'city' => $response->json('localidade') ?: null,
            'state' => $response->json('uf') ?: null,
        ];
    }
}
```

> Timeout curto (3s, item em aberto #14 do doc de regras) e captura só de `ConnectionException` — erros HTTP (`4xx`/`5xx`) já são tratados por `$response->ok()`. Chamado tanto pelo componente Livewire de checkout (RF-35) quanto, futuramente, pelo formulário de cliente do painel (item em aberto #12).

### 3.6 Resolução da taxa de entrega por bairro

Implementa RN-37/RN-38, no mesmo espírito de `ResolvePriceForCartLine` (preço sempre resolvido no servidor, RN-13).

```php
// app/Actions/Orders/ResolveDeliveryFee.php (implementado em 20/08/2026, RN-37a somada em 20/08/2026)
namespace App\Actions\Orders;

use App\Exceptions\CheckoutException;
use App\Models\DeliveryOption;
use App\Models\DeliveryZoneNeighborhood;
use App\Support\CurrentTenant;
use App\Support\NeighborhoodNormalizer;

class ResolveDeliveryFee
{
    /**
     * @return array{fee: float, delivery_zone_id: ?int, is_unlisted_neighborhood: bool, base_fee: float, unlisted_surcharge: float}
     *
     * @throws CheckoutException quando o bairro não está mapeado e o tenant não atende bairros não configurados (RN-37, caso 3).
     */
    public function __invoke(DeliveryOption $deliveryOption, ?string $neighborhood, ?string $city, float $itemsTotal): array
    {
        $tenant = CurrentTenant::get();

        // Onboarding: tenant ainda não cadastrou nenhum setor — cai pro
        // comportamento anterior (taxa fixa da DeliveryOption), pra não
        // bloquear quem ainda não migrou pra taxa por bairro.
        if ($tenant->deliveryZones()->doesntExist()) {
            $baseFee = $this->applyFreeDeliveryThreshold((float) $deliveryOption->delivery_fee, $deliveryOption, $itemsTotal);

            return ['fee' => $baseFee, 'delivery_zone_id' => null, 'is_unlisted_neighborhood' => false, 'base_fee' => $baseFee, 'unlisted_surcharge' => 0.0];
        }

        $normalizedNeighborhood = NeighborhoodNormalizer::normalize($neighborhood);
        $normalizedCity = NeighborhoodNormalizer::normalize($city);

        $match = $normalizedNeighborhood
            ? DeliveryZoneNeighborhood::with('deliveryZone')
                ->where('neighborhood', $normalizedNeighborhood)
                ->when($normalizedCity, fn ($query) => $query->where('city', $normalizedCity))
                ->first()
            : null;

        if ($match) {
            $baseFee = $this->applyFreeDeliveryThreshold((float) $match->deliveryZone->delivery_fee, $deliveryOption, $itemsTotal);

            return ['fee' => $baseFee, 'delivery_zone_id' => $match->delivery_zone_id, 'is_unlisted_neighborhood' => false, 'base_fee' => $baseFee, 'unlisted_surcharge' => 0.0];
        }

        if (! $tenant->serves_unlisted_neighborhoods) {
            throw new CheckoutException('A entrega não está disponível para o bairro informado.');
        }

        // RN-37a: soma, não substitui — taxa normal da opção (com isenção
        // por pedido mínimo já aplicada) + taxa de bairro não configurado.
        $baseFee = $this->applyFreeDeliveryThreshold((float) $deliveryOption->delivery_fee, $deliveryOption, $itemsTotal);
        $unlistedSurcharge = (float) $tenant->unlisted_neighborhood_fee;

        return ['fee' => round($baseFee + $unlistedSurcharge, 2), 'delivery_zone_id' => null, 'is_unlisted_neighborhood' => true, 'base_fee' => $baseFee, 'unlisted_surcharge' => $unlistedSurcharge];
    }

    private function applyFreeDeliveryThreshold(float $fee, DeliveryOption $deliveryOption, float $itemsTotal): float
    {
        if ($deliveryOption->min_order_for_free_delivery !== null && $itemsTotal >= $deliveryOption->min_order_for_free_delivery) {
            return 0.0; // RN-38 — zera só a taxa normal; unlisted_surcharge é somado depois, fora daqui.
        }

        return $fee;
    }
}
```

> A normalização de bairro/cidade (`App\Support\NeighborhoodNormalizer`, item em aberto #13/#6 resolvido) usa `Str::ascii()->lower()` e é aplicada tanto na gravação de `delivery_zone_neighborhoods` (mutator no model) quanto na busca aqui — evita falso "bairro não configurado" por acento/maiúscula divergentes. `CreateOrderFromCart` chama esta action sempre que um `delivery_option_id` foi escolhido no checkout (RN-30: ausência de `delivery_option_id` já significa retirada, sem precisar de coluna `type`), gravando `delivery_zone_id` e `is_unlisted_neighborhood` no pedido (seção 3.3). O checkout usa `base_fee`/`unlisted_surcharge` pra mostrar o detalhamento ao cliente antes de confirmar (RN-37a, RF-39) — nunca só o total somado.

---

## 4. Middleware e Resolução de Tenant

### 4.1 Fluxo de resolução (por path)

Dois caminhos de entrada, ambos convergindo em `App\Support\CurrentTenant` setado
+ `URL::defaults(['tenant' => $slug])` + `PermissionRegistrar::setPermissionsTeamId()`:

**Cardápio público — `razelfood.com.br/{slug}/...`**
1. A rota casa o grupo `Route::prefix('{tenant}')` de `routes/web.php`.
2. O middleware `App\Http\Middleware\ResolveTenantFromPath` (middleware **do grupo**,
   roda depois do routing) lê `$request->route('tenant')`.
3. Slug reservado (seção 4.2) ou não resolvido → **`abort(404)`** (nunca segue sem
   tenant); `status != Active` → `abort(503)`. Resolução cacheada em
   `Cache::remember("tenant:slug:{$slug}", ttl)`.

**Painel do tenant — `razelfood.com.br/painel/{slug}`**
1. **Tenancy nativa do Filament** — `TenantPanelProvider` declara
   `->tenant(Tenant::class, slugAttribute: 'slug')`. O Filament resolve o `{tenant}`
   por route-model binding contra `tenants.slug` e chama `User::canAccessTenant()`
   (403/404 se falhar).
2. `App\Http\Middleware\ApplyTenantScopes` (registrado como
   `->tenantMiddleware([...], isPersistent: true)`, roda também nas requisições
   Livewire AJAX) faz a ponte `Filament::getTenant()` → `CurrentTenant` +
   `URL::defaults` + spatie-teams. O `TenantScope` global continua como 2ª camada.

**Painel central — `razelfood.com.br/admin`** — nenhum middleware de tenant.
`CurrentTenant::id()` fica `null` → `TenantScope` não filtra → super admin enxerga
todos os tenants.

### 4.2 Slugs reservados — `config/tenancy.php`

Com resolução por path, o primeiro segmento de `/{slug}` colide com **toda** rota de
primeiro nível do sistema. A lista é crítica e usada em três lugares: a constraint
`->where('tenant', ...)` do grupo (lookahead negativo montado da lista), a checagem
defensiva em `ResolveTenantFromPath`, e `App\Rules\ValidTenantSlug`.

```php
// config/tenancy.php — 'reserved_slugs' (resumo; ver o arquivo p/ a lista completa)
'admin', 'painel', 'central', 'interno', 'login', 'logout',
'livewire', 'filament', 'sanctum', 'storage', 'up', 'build', 'vendor',
'assets', 'static', 'css', 'js', 'images', 'img', 'fonts',
'favicon.ico', 'robots.txt',
'checkout', 'acompanhar', 'comanda', 'entrega', 'relatorios',
'www', 'app', 'api', 'suporte', 'blog', 'mail', 'ftp', 'cdn', 'docs',
'help', 'status', 'dev', 'staging', 'homolog', 'teste', 'demo',
'minhaconta', 'cardapio', 'pedido', 'pedidos',
```

> Ao adicionar uma rota de primeiro nível nova fora do grupo `{tenant}`, adicione o
> segmento aqui também.

### 4.3 Middlewares

**`app/Http/Middleware/ResolveTenantFromPath.php`** (evolução do antigo `IdentifyTenant`):
lê `$request->route('tenant')` em vez do `Host`; **não** é mais middleware global
(saiu do `prepend` em `bootstrap/app.php`); `abort(404)` obrigatório se não resolver.
Seta `app()->instance(Tenant::class)`, `CurrentTenant::set()`,
`URL::defaults(['tenant' => $slug])`, `setPermissionsTeamId($tenant->id)`.

**`app/Http/Middleware/ApplyTenantScopes.php`** (novo): tenant middleware **persistente**
do painel do tenant. `$tenant = Filament::getTenant()` (já resolvido/validado pelo
Filament) → mesma ponte de `CurrentTenant`/`URL::defaults`/spatie-teams.

**`app/Support/CurrentTenant.php`** — inalterado, exceto o novo `forget()` (limpa o
holder entre casos de teste / workers long-lived).

`routes/web.php`:

```php
Route::get('/', [LandingController::class, 'index'])->name('landing');

$reservedSlugPattern = collect(config('tenancy.reserved_slugs'))
    ->map(fn (string $s) => preg_quote($s, '/'))->implode('|');

Route::prefix('{tenant}')
    ->where(['tenant' => '(?!(?:'.$reservedSlugPattern.')$)[a-z0-9]+(?:-[a-z0-9]+)*'])
    ->middleware(ResolveTenantFromPath::class)
    ->group(function () {
        Route::get('/', Menu::class)->name('menu.index');
        Route::get('/checkout', Checkout::class)->name('checkout.index');
        Route::get('/acompanhar/{order}', [OrderTrackingController::class, 'show'])->name('order.tracking');
        Route::get('/comanda/{order}', [OrderTicketController::class, 'show'])->name('order.ticket');
        Route::get('/relatorios/pedidos/imprimir', [OrdersReportPrintController::class, 'show'])->name('reports.orders.print');
        Route::get('/relatorios/entregas/imprimir', [DeliveriesReportPrintController::class, 'show'])->name('reports.deliveries.print');
        Route::match(['GET', 'POST'], '/entrega/{order}', DeliveryConfirmationController::class)
            ->name('delivery.confirmation')->middleware('signed');
    });
```

> `URL::defaults(['tenant' => $slug])` é o ponto de costura: quase todo
> `route('menu.index')` / `route('order.tracking', ['order' => $token])` nas views e
> actions OMITE o parâmetro `tenant` — ele entra pelo default. O bug antigo de
> `Route::domain('{tenant}...')` capturar o route-model binding não existe mais.

### 4.4 Trait `BelongsToTenant` + Global Scope

```php
// app/Models/Scopes/TenantScope.php
namespace App\Models\Scopes;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($tenantId = CurrentTenant::id()) {
            $builder->where($model->getTable() . '.tenant_id', $tenantId);
        }
    }
}
```

```php
// app/Models/Concerns/BelongsToTenant.php
namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Support\CurrentTenant;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model) {
            if (empty($model->tenant_id) && CurrentTenant::id()) {
                $model->tenant_id = CurrentTenant::id();
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }
}
```

Todo model de domínio (`Category`, `Product`, `FlashPromotion`, `FlashPromotionProduct`, `Client`, `Order`, `BusinessHour`, `DeliveryOption`, `DeliveryZone`, `DeliveryZoneNeighborhood`, `PaymentOption`) usa a trait:

```php
class Product extends Model
{
    use BelongsToTenant, SoftDeletes;
    // ...
}
```

> **Implementado (RNF-01):** a classe abstrata `App\Models\Concerns\TenantScopedModel`
> (que já aplica `BelongsToTenant`) — todo model de domínio a estende em vez de `Model`.
> Além do `TenantScope` do projeto, o painel do tenant aplica **também** o escopo nativo
> do Filament (ownership relationship `tenant()`) — dupla camada. Exceções que precisam
> de filtro manual em `getEloquentQuery()`: `RoleResource` (model Spatie sem `tenant()`,
> `$isScopedToTenant = false`) e `UserResource` (`User` é `Authenticatable` puro).

### 4.5 Componentes Livewire públicos — `EstablishesTenantContext`

`POST /livewire/update` (a rota do Livewire) **não** passa pelo grupo
`Route::prefix('{tenant}')` — é `livewire/update` pura, middleware `web` só. Sem
tratamento, a partir do 1º `wire:*` depois do page load `CurrentTenant` fica `null`,
o `TenantScope` para de filtrar e `route('checkout.index')` estoura
`UrlGenerationException`.

Os componentes públicos (`app/Livewire/Menu.php`, `Checkout.php`,
`OrderStatusTimeline.php`) usam a trait
`App\Livewire\Concerns\EstablishesTenantContext`: guardam o slug numa property
pública `$tenantSlug` (protegida pelo checksum do snapshot do Livewire 3) e
re-resolvem o tenant no hook `bootedEstablishesTenantContext()` a cada requisição.
O painel Filament não tem esse problema (`Livewire::addPersistentMiddleware` do
próprio Filament + `ApplyTenantScopes`).

### 4.6 Painéis Filament (tenant vs. central)

Dois `PanelProvider`, **sem `->domain()`**:

- **`TenantPanelProvider`** — `->path('painel')` + `->tenant(Tenant::class, slugAttribute: 'slug')`
  → URL `razelfood.com.br/painel/{slug}`. `->tenantMiddleware([ApplyTenantScopes::class], isPersistent: true)`,
  `->tenantMenu(false)` (1 tenant por usuário — sem switcher), `->login()` (login em
  `/painel/login`, fora do contexto de tenant). Resources: cardápio, pedidos, clientes,
  configurações, usuários, papéis — escopados por `TenantScope` + escopo nativo do Filament.
- **`CentralPanelProvider`** — `->path('admin')`, `->default()`, sem `->tenant()`, sem Shield.
  `TenantResource`/`PlanResource`/`FeatureResource`/localidades para a equipe Razel Tec.

### 4.7 Autenticação e acesso entre painéis

- **Guard único `web`, cookie de sessão host-only no apex** (`SESSION_DOMAIN=null`).
  Central (`/admin`) e tenants (`/painel/{slug}`) compartilham o cookie — não há guard
  por painel (adotar um quebraria ~26 testes de painel e todo `auth()->user()` do
  painel). O isolamento é `User::canAccessPanel()` + `User::canAccessTenant()`, ambos
  checados pelo Filament a cada requisição.
- `User implements HasTenants`:
  - `canAccessPanel('tenant')` = `tenant_id !== null || hasCentralRole(Platform)`.
  - `canAccessTenant($tenant)` = tenant do próprio usuário **ou** super admin Plataforma.
  - `getTenants()` = `[$this->tenant]` (ou `Tenant::all()` para Plataforma).
- **Super admin "Plataforma" acessa o painel de qualquer tenant** (RN-44) — um
  `Gate::before` em `AppServiceProvider` retorna `true` para `hasCentralRole(Platform)`,
  liberando a autorização do Filament Shield no painel do tenant. Usuário comum de
  tenant em `/admin` → 403.
- Controllers fora do Filament que checam tenant à mão (comanda, relatórios
  imprimíveis) usam `User::canOperateInCurrentTenant()` (mesmo critério, cobre o
  super admin) no lugar de `tenant_id === CurrentTenant::id()`.
- `email` é globalmente único → 1 usuário = 1 tenant. `tenant_id` de escrita **nunca**
  vem do payload — sempre `CurrentTenant::id()` (hook `creating` da trait).

### 4.8 Controle de acesso por feature (Filament)

Implementa RN-43 (RF-42, RF-43): duas camadas, nunca só uma. Confirmado na documentação do Filament 4 (e na assinatura real instalada no projeto) — `shouldRegisterNavigation(): bool` só esconde o link do menu, **não impede acesso direto por URL**; quem bloqueia de fato é `canAccess(): bool` (sem parâmetros nesta versão), reavaliado a cada requisição Livewire (inclusive se o plano do tenant mudar no meio de uma sessão aberta).

**Implementado:** trait `App\Filament\Tenant\Concerns\GatedByFeature`, aplicada a `CategoryResource`, `ProductResource`, `FlashPromotionResource` (`cardapio_digital`), `DeliveryOptionResource`, `DeliveryZoneResource`, `PaymentOptionResource` (`configuracoes_estabelecimento`), `OrderResource` (`historico_pedidos`), `ProductionLineResource` (`linhas_producao`) e `RoleResource` (`usuarios_permissoes`):

```php
// app/Filament/Tenant/Concerns/GatedByFeature.php
trait GatedByFeature
{
    abstract public static function requiredFeature(): string;

    public static function shouldRegisterNavigation(): bool
    {
        return static::tenantHasRequiredFeature() && parent::shouldRegisterNavigation();
    }

    public static function canAccess(): bool
    {
        return static::tenantHasRequiredFeature() && parent::canAccess();
    }

    protected static function tenantHasRequiredFeature(): bool
    {
        return CurrentTenant::get()?->hasFeature(static::requiredFeature()) ?? false;
    }
}
```

> **Sempre combinar com `parent::canAccess()`/`parent::shouldRegisterNavigation()`, nunca substituir** — senão o gate por feature pula a autorização por role/policy (Shield) que o Resource já tinha antes.

**Armadilha real, achada ao gatear `Kitchen`/`OrderSettings` (Pages que usam `HasPageShield`):** duas traits definindo `canAccess()`/`shouldRegisterNavigation()` na mesma classe colidem — PHP não deixa aplicar as duas sem resolução explícita. E mesmo resolvendo a colisão, se a classe declarar seu próprio `canAccess()`, `parent::canAccess()` chamado de dentro dele **pula qualquer trait** (traits não entram na cadeia de herança que `parent::` percorre — confirmado empiricamente, não só por leitura de doc). A saída é dar **alias** ao método do `HasPageShield` antes de declarar o `canAccess()` da classe:

```php
// app/Filament/Tenant/Pages/Kitchen.php
class Kitchen extends Page
{
    use HasPageShield {
        canAccess as pageShieldCanAccess;
        shouldRegisterNavigation as pageShieldShouldRegisterNavigation;
    }

    public static function canAccess(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::CENTRAL_DE_PEDIDOS) ?? false)
            && static::pageShieldCanAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::CENTRAL_DE_PEDIDOS) ?? false)
            && static::pageShieldShouldRegisterNavigation();
    }
}
```

`GatedByFeature` (a trait genérica acima) **não é usada** em `Kitchen`/`OrderSettings` por causa disso — o padrão de alias é escrito à mão nessas duas classes. Mesmo padrão vale pra qualquer Page futura que precise combinar feature-gate com `HasPageShield`.

### 4.9 Painel central — gestão de planos e features (RF-40, RF-41)

`CentralPanelProvider` (seção 4.6) ganha dois recursos novos, visíveis só para usuários com `tenant_id = null`:

- **`PlanResource`** — CRUD de planos, com um `CheckboxList`/`Select` múltiplo de features (via `plan_feature`) no formulário.
- **`FeatureResource`** — CRUD do catálogo de features, incluindo o toggle `is_available` (RN-42). Alterar `is_available` de `true` para `false` deve, na prática, revogar o acesso de todo tenant que a tinha via plano ou override — não precisa de job de limpeza, porque `hasFeature()` já checa `is_available` em tempo real (seção 3.1.1).
- `TenantResource` (já existente, RF-03) ganha um campo `plan_id` (`Select::make('plan_id')->relationship('plan', 'name')`) e uma seção/`RelationManager` para gerenciar os overrides daquele tenant (RF-41).

---

## 5. Concorrência e Integridade

- Baixa de saldo de promoção relâmpago (`flash_promotions.sold_quantity`, `flash_promotion_products.sold_quantity`) e criação do pedido devem ocorrer na **mesma transação de banco**, com `lockForUpdate()` na linha da promoção antes de validar saldo — replica RN-22 do documento de regras.
- Contagem de `products.sales_count` (usada em "mais vendidos", RN-15) deve ser incrementada de forma atômica (`increment()`) na confirmação do pedido, não recalculada por agregação pesada a cada carregamento do cardápio.
- Toda operação de escrita relevante para o pedido deve ocorrer dentro do escopo do tenant resolvido — nunca aceitar um `tenant_id` vindo do payload da requisição; ele sempre vem de `CurrentTenant::id()`.

---

## 6. Checklist de Homologação (isolamento multi-tenant)

Antes de considerar a arquitetura multi-tenant homologada, validar:

- [ ] Criar dois tenants de teste (A e B) com produtos, categorias e clientes com nomes parecidos propositalmente.
- [ ] Confirmar que uma consulta em `Product::all()` autenticado no contexto do tenant A **nunca** retorna produtos do tenant B, mesmo sem `where` explícito no código da feature.
- [ ] Usuário do tenant A autenticado, `GET /painel/{slug-de-B}` → 404 (`canAccessTenant`). Trocar o slug na URL do painel logado nunca dá acesso.
- [ ] `GET /painel/{slug}` sem sessão → redireciona para `/painel/login`.
- [ ] `GET /painel/{slug}/products/{id de produto de B}` no painel de A → 404 (resolução de record escopada).
- [ ] Super admin "Plataforma" logado em `/admin` acessa `/painel/{qualquer-slug}` (RN-44); usuário comum de tenant em `/admin` → 403.
- [ ] Adicionar item no carrinho em `/{slug-A}/`, abrir `/{slug-B}/` → carrinho vazio (chave de sessão namespaced por tenant).
- [ ] Selecionar um produto no cardápio (dispara `POST /livewire/update`) → sem `UrlGenerationException` (a trait `EstablishesTenantContext` restaura o contexto).
- [ ] Confirmar que um slug reservado (`admin`, `painel`, `checkout`, `livewire`, …) nunca é aceito na criação de tenant nem casa a rota `/{tenant}`.
- [ ] Cobertura automatizada: `tests/Feature/Tenancy/{CrossTenantIsolationTest,TenantPathResolutionTest}.php`.
- [ ] Confirmar que dois pedidos concorrentes na última unidade de uma promoção relâmpago do **mesmo tenant** resultam em só um pedido confirmado com o preço promocional (teste de concorrência, RN-22).
- [ ] Confirmar que promoções relâmpago do tenant A não aparecem nem afetam o saldo de promoções do tenant B, mesmo com nomes de promoção idênticos.
- [ ] Confirmar que a página de acompanhamento de pedido (`/acompanhar/{order}`) de um tenant não permite ver pedido de outro tenant trocando o ID na URL (o `order` também precisa estar sob o `TenantScope`).
- [ ] Rodar a suíte de testes automatizados (Pest/PHPUnit) cobrindo os itens acima como testes de feature, não só validação manual.
- [ ] Atribuir tenants A e B a planos diferentes; confirmar que um Resource gated por feature aparece no menu de um e não do outro.
- [ ] Tentar acessar a URL de um Resource gated por feature diretamente (sem passar pelo menu) num tenant sem a feature → `canAccess()` deve barrar, não só esconder o link (RN-43).
- [ ] Criar um override `enabled = false` numa feature que o plano do tenant inclui → confirmar que o override vence o plano (RN-41).
- [ ] Marcar uma feature como `is_available = false` enquanto um tenant a tem no plano → confirmar que ela deixa de aparecer/ser acessível imediatamente (RN-42), sem precisar de cache/job de limpeza.

---

## 7. Fora de Escopo Nesta Fase (reforço)

Consistente com a seção 9 do documento de regras de negócio — não implementar neste momento:

- Caixa, estoque avançado, compras, emissão fiscal/NFe — **os módulos em si**. O catálogo de features (seção 3.1.1) pode conter uma entrada reservada (`is_available = false`) para eles, mas isso é só um placeholder de roadmap, não a funcionalidade.
- Pagamento online integrado (cartão/Pix automático).
- Trial, upgrade/downgrade automatizado, cobrança pró-rata por plano — a **infraestrutura** de planos/features está dentro de escopo agora (RN-39 a RN-44); a precificação por plano não está.
- Domínio próprio por cliente via CNAME (upsell futuro — RN-06 — a arquitetura por path não impede, ver seção 9).
- Onboarding self-service (hoje o provisionamento de tenant é assistido pela equipe Razel Tec — RN-09).

---

## 8. Itens em Aberto Específicos Desta Modelagem

1. ~~Nomenclatura em inglês vs. português~~ — **confirmado em 19/08/2026**: inglês (seção 1).
2. ~~`tenant_id` em `flash_promotion_products`~~ — **confirmado em 19/08/2026**: entra, com índice e escopada pela trait `BelongsToTenant` como qualquer outra tabela de domínio (seção 3.3).
3. ~~**Cache de resolução de tenant** (seção 4.3)~~ — **implementado**: `Cache::remember("tenant:slug:{slug}", ttl)` em `ResolveTenantFromPath`, store `database` (`CACHE_STORE=database`), TTL `config('tenancy.cache.ttl_minutes')` (5 min). Trocar o slug pelo painel central invalida as duas chaves (`EditTenant::changeSlug`).
4. ~~Tratamento do primeiro cliente já fechado~~ — **confirmado em 19/08/2026**: nasce como um tenant normal dentro da arquitetura multi-tenant, sem tratamento especial.
5. ~~Modelo de taxa de entrega~~ — **confirmado em 20/08/2026**: taxa por setor/bairro (RN-34 a RN-38), substituindo a taxa fixa única por `DeliveryOption` para modalidades de entrega. Ver seções 3.3, 3.5, 3.6.
6. ~~Normalização de bairro para casar `delivery_zone_neighborhoods.neighborhood` com o retorno do ViaCEP~~ — **resolvido em 20/08/2026**: `App\Support\NeighborhoodNormalizer` (ascii + minúsculas) aplicado tanto na gravação (mutator do model) quanto na busca em `ResolveDeliveryFee` (seção 3.6).
7. **`min_order_for_free_delivery` continua em `delivery_options`, não em `delivery_zones`** — assumido que a isenção por valor mínimo é por opção de entrega (ex.: só para "Entrega motoboy"), não por setor individual. Confirmar se algum tenant precisa de isenção diferente por setor — não suportado no desenho atual.
8. ~~Catálogo de features e planos por tenant~~ — **decidido em 20/08/2026**: `features` + `plans` + `plan_feature` + `tenant_feature_overrides`, com reforço de acesso em duas camadas (`shouldRegisterNavigation()` + `canAccess()`) no Filament. Ver seções 3.1.1, 4.9 e 4.10. PDV/estoque/NF-e entram só como entradas reservadas (`is_available = false`) — implementação funcional de cada módulo continua sendo decidida separadamente (item 11 de `requisitos-regras-negocio.md`).

---

## 9. Hospedagem — Compartilhada (HostGator)

Compartilhado na HostGator, com possível migração futura para VPS conforme a base cresce.
Com a **tenancy por path num domínio único**, a hospedagem compartilhada deixou de ser um
risco: não há mais wildcard DNS nem wildcard SSL (era o maior risco técnico da V1).

- **Domínio / SSL:** um registro para `razelfood.com.br` (+ `www`), document root do apex
  na pasta `public/` do app, **certificado SSL simples** (AutoSSL do cPanel). Todos os
  tenants respondem no mesmo host. Provisionar um tenant novo é um `INSERT` em `tenants`.
- **SSH e Composer:** o deploy usa SSH — `git pull origin producao` + comandos artisan.
  A branch `producao` já vem com `vendor/` (sem dev) e `public/build/` compilados
  (`scripts/promote-producao.sh` reconstrói tudo localmente), então o servidor **não**
  precisa de Composer/npm.
- **PHP:** fixado em 8.4.x no cPanel (MultiPHP Manager).
- **Sessão:** `SESSION_DRIVER=file`, `SESSION_DOMAIN=null` (cookie host-only no apex),
  `SESSION_SECURE_COOKIE=true`. Central e tenants compartilham o cookie — o isolamento é
  `canAccessPanel`/`canAccessTenant` (seção 4.7).
- **Sem worker de fila persistente:** queue driver `database`; dois cron jobs no cPanel
  (a cada minuto): `php artisan schedule:run` e `php artisan queue:work --stop-when-empty --max-time=50`.
  Jobs (reset de promoção recorrente, sync de localidades) assumem execução a cada 1 min.
- **Cache:** `CACHE_STORE=database` (sem Redis/Memcached) — a entrada `tenant:slug:{slug}`
  do `ResolveTenantFromPath` sobrevive a restart; `optimize:clear` / `cache:clear` limpam.
- **Deploy:** `php artisan optimize:clear` em **todo** deploy após `git pull`; cachear só
  `config:cache`, `event:cache`, `view:cache`, `filament:optimize`. **NUNCA `route:cache`**
  (route cache defasado já removeu a rota do `livewire.min.js` → Filament inacessível).
  Ver `.ai/rules/scripts.md` e o modelo `.env.hostgator.example`.
- **Migrations são sempre aditivas** (`ADD COLUMN`) — `migrate --force` é seguro no deploy.

> **Domínio próprio por cliente (CNAME, upsell futuro — RN-06):** a arquitetura por path
> não impede — bastaria mapear `Route::domain('{customDomain}')` para um tenant específico
> além do grupo `/{tenant}`. Fora de escopo por ora.

---

*Este documento deve ser lido em conjunto com `requisitos-regras-negocio.md`. Qualquer mudança de escopo do produto deve primeiro atualizar aquele documento, e só depois refletir aqui.*