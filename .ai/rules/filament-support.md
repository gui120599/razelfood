---
paths:
  - app/Filament/Support/EstablishmentDocumentFields.php
  - app/Filament/Support/InputMasks.php
---

# Filament Support

## CNPJ + endereço do tenant: seção compartilhada entre os dois painéis
Colunas nullable em `tenants`: cnpj, zip_code, street, number, complement, neighborhood, city, state. Editáveis em DOIS lugares: painel central (`TenantForm`) e painel do tenant (`EstablishmentSettings`, gateado pela feature `configuracoes_estabelecimento`). Os campos vêm SÓ de `App\Filament\Support\EstablishmentDocumentFields::section()`; `::names()` alimenta o `mount()->only()` do EstablishmentSettings. Ao mexer nesses campos, editar só o helper.
- CNPJ: validado por `App\Rules\ValidCnpj` (DV módulo 11, aceita com/sem máscara, vazio passa). Gravado **SÓ com os 14 dígitos** (sem máscara) — `InputMasks::cnpj()` aplica `->stripCharacters(['.', '/', '-'])`, que roda antes da validação e do save. No form de edição a máscara reexibe o valor formatado.
- CEP (`zip_code`): gravado **só com os 8 dígitos** — `InputMasks::cep()` aplica `->stripCharacters('-')`. `->live(onBlur:true)` + `fillAddressFromCep()` chama `App\Services\Address\ViaCepClient` (padrão da casa, RN-33 — NÃO IBGE p/ endereço) e seta street/neighborhood/state/city (nessa ordem: UF antes da cidade). Falha/não encontrado só notifica, nunca limpa o form.
- WhatsApp (`whatsapp_number` em `TenantForm`/`EstablishmentSettings`): gravado **só dígitos** via `InputMasks::phone()`.
- UF/Cidade: `Select` consumindo `App\Services\Address\IbgeService` (states()/citiesOf($uf)) ao vivo — MESMO padrão de `LocationSyncForm::stateAndCityFields()`. `state` guarda a sigla, `city` guarda o nome. O Select de cidade tem `->dehydrated()` explícito porque `->disabled()` (quando UF vazia) sozinho tira o valor do save.
Testes que renderizam esses forms precisam `Http::fake('servicodados.ibge.gov.br/*')`. `fillForm(['state'=>...,'city'=>...])` numa chamada só NÃO funciona: o `afterStateUpdated` do state zera o city — preencher em dois `fillForm()` separados.

## InputMasks: helpers são transformers `(TextInput): TextInput`, não getters de máscara
`App\Filament\Support\InputMasks::{phone,cep,cnpj,money}()` recebem o `TextInput` e devolvem ele já com máscara + normalização. Uso: `InputMasks::cnpj(TextInput::make('cnpj')->label('CNPJ'))->maxLength(18)->...` — nunca `->mask(InputMasks::cnpj())`.
- `phone/cep/cnpj`: aplicam `->mask(...)->stripCharacters([...])`. O `stripCharacters` tira os caracteres do formato ANTES da validação, então o banco guarda só dígitos e `ValidCnpj`/`maxLength`/`unique` enxergam só dígitos. Seguro fazer strip indiscriminado aqui (diferente de `money()`): '(', ')', ' ', '.', '/', '-' nunca são significativos num telefone/CEP/CNPJ.
- `money()`: caso especial — NÃO usa `stripCharacters` nem `->numeric()` (ver docblock longo no arquivo). Gera o pattern da máscara à mão e normaliza com `normalizeMoneyState()` em `afterStateUpdated` + `dehydrateStateUsing`, gravando decimal puro.
- **Exibição**: como o banco guarda só dígitos, `TextColumn`/`TextEntry` que mostram esses campos reconstroem a máscara com `InputMasks::formatPhone()/formatCep()/formatCnpj()` (puros, `(?string): ?string`, devolvem intacto o que não bate a contagem de dígitos) via `->formatStateUsing(fn (?string $state) => InputMasks::formatPhone($state))`. Aplicado hoje em `ClientsTable.phone`, `TenantsTable.whatsapp_number`, `OrderInfolist.client.phone`. `formatPhone` cobre 10/11 dígitos e 12/13 (com código do país).
- Como o valor já vem normalizado do banco, consumidor novo não precisa re-normalizar (o `preg_replace` que sobrou em `Checkout::whatsappUrl` virou no-op defensivo). `FindOrCreateClient::normalizePhone()` continua no fluxo público do cardápio (não passa por Filament).
