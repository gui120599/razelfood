---
paths:
  - 'app/{Livewire/Menu.php,Actions/Menu/ResolvePriceForCartLine.php,Models/Category.php,Models/FlavorQuantityOption.php}'
---

# Menu

## Quantidade de sabores é configurável por tenant, não um teto único
categories.max_flavors foi removida. A quantidade de sabores que o cliente pode escolher por categoria vem da tabela `flavor_quantity_options` (tenant + category scoped, label + flavor_count), editável pelo Admin via FlavorQuantityOptionsRelationManager em CategoryResource (aba só aparece se allows_flavors=true).

Regras:
- Uma opção com flavor_count=1 ("sabor único") é tratada como item SIMPLES no carrinho (Cart::addSimple), não como combo — o tipo 'combo' em Cart/ResolvePriceForCartLine continua exigindo >=2 sabores (RN-16: "combinação de dois ou mais produtos").
- ResolvePriceForCartLine::resolveCombo valida a quantidade de sabores contra `$category->flavorQuantityOptions()->pluck('flavor_count')`, não mais contra um max fixo.
- Menu.php: modal de combo é ÚNICO, sem etapas (unificado em 20/08/2026 — antes eram 2 modais/etapas separados). `startCombo(categoryId, productId = null)` já abre com a quantidade padrão selecionada (menor `flavor_count` cadastrada) e, se veio de um "+" de produto específico, esse produto já entra pré-selecionado em `flavor_ids`. `selectFlavorQuantity()` NUNCA zera `flavor_ids` — só trunca (`array_slice`) se a nova quantidade for menor, preservando os primeiros escolhidos (inclusive o pré-selecionado). `toggleFlavor()`/`confirmCombo()` continuam exigindo count EXATO igual a `required_count`.
- Se uma categoria allows_flavors=true não tem nenhuma flavor_quantity_options cadastrada, o "+" deve cair no addToCart() direto (ver gate `$category->flavorQuantityOptions->isNotEmpty()` em product-card.blade.php) — nunca travar o cliente sem conseguir comprar.
- RN-23 (20/08/2026): `addToCart()`, `startCombo()` e `confirmCombo()` bloqueiam (sem adicionar nada, só abrem o drawer do carrinho com `showCart=true`) quando `$this->businessHours->isOpen` é false — o bloqueio de turno fechado acontece já na escolha do primeiro item, não só no checkout (`CreateOrderFromCart`/`CheckBusinessHours` continuam sendo a validação autoritativa no servidor).

## Adicionais no cardápio público: sub-passo interno no combo, nunca modal novo
RN-48 (22/08/2026): o "+" do product-card ganhou um 3º branch — `resolved_has_addons` (calculado em `Menu::attachPrice()`) abre a visualização rápida (`viewProduct`) em vez de adicionar direto, quando o produto não tem sabores mas tem adicionais.

Pro combo, a disciplina de "modal único, sem etapas" (decidida 20/08/2026, já documentada acima) foi preservada: `comboBuilder` ganhou a chave `step` ('flavors'|'addons'). `confirmCombo()` só finaliza direto (Cart::addCombo/addSimple) se `comboAddons` (computed) estiver vazio; senão muda `step` pra 'addons' e o MESMO modal passa a renderizar o picker de adicionais (quantidade + seletor de alvo produto-inteiro/sabor-específico). `confirmComboAddons()` finaliza. `cancelCombo()` sempre reseta `step` pra 'flavors' junto com o resto do array — qualquer teste que faça `->set('comboBuilder', [...])` direto (bypassando os métodos) precisa incluir a chave `step`, senão o Blade quebra com "Undefined array key" (bug real já encontrado em MenuBusinessHoursLockTest).

`Cart::addSimple()`/`addCombo()` ganharam o parâmetro `addons` (array de `{addon_id, quantity, target}`), e `App\Models\Addon`/`ProductAddon` entram no fluxo de preço via `ResolvePriceForCartLine::resolveAddons()` — ver [[support]] pro rateio de estoque/preço por `target_share`.

## Sub-passo de adicionais do combo: gate sim/não + sem seletor de alvo com 1 sabor
O sub-passo `comboBuilder['step'] === 'addons'` (RN-48) ganhou duas regras espelhando o AddonPickerModal do painel interno (app/Filament/Tenant/Livewire/Orders/AddonPickerModal.php):

1. Antes de mostrar a lista de adicionais, pergunta sim/não (`$comboAddonsGate`, null=ainda não perguntado). `chooseComboWantsAddons(false)` finaliza o combo direto sem adicionais; `true` revela a lista. Reset em `startCombo()`/`cancelCombo()`. Continua "modal único" (RN — .ai/rules/menu.md) porque é só mais um estado dentro do MESMO step, não um step novo.
2. O seletor de alvo (produto inteiro vs. sabor específico) só aparece com `count($comboBuilder['flavor_ids']) > 1` — com 1 sabor (categoria "sabor único", flavor_count=1) não faz sentido perguntar. Isso vale tanto no Blade (`@if ($quantity > 0 && count($comboBuilder['flavor_ids']) > 1)`) quanto em `comboAllowsWholeProduct()`/`comboFlavorOptionsFor()`.

Trap: `comboFlavorOptionsFor()` é tipado pra retornar `Illuminate\Database\Eloquent\Collection` (import no topo do arquivo) — usar `collect()` (Support\Collection) no early-return quebra com TypeError; usar `new Collection` (o import correto).

Testes em tests/Feature/MenuAddonTest.php.

## Sub-passo de adicionais do combo: botão "Prosseguir sem adicionais"
Complementa [[Sub-passo de adicionais do combo: gate sim/não + sem seletor de alvo com 1 sabor]]: depois que o cliente já respondeu "sim" no gate e está vendo a lista, `skipComboAddons()` (Menu.php) e `skipAddons()` (AddonPickerModal.php, painel) dão um atalho pra sair sem nenhum adicional a qualquer momento — limpam seleções parciais e finalizam a linha vazia. Botão "Prosseguir sem adicionais" ao lado do botão principal de confirmar, nas duas views (public menu.blade.php e o painel addon-picker-modal.blade.php), mais um texto informativo acima da lista lembrando que deixar a quantidade em 0 já basta. O mesmo texto informativo também foi ao viewingProductAddons (visualização rápida de produto simples com adicionais) por simetria — ali não tem botão extra porque só existe um único "Adicionar ao pedido" mesmo (já lida com quantidade 0 nativamente).
