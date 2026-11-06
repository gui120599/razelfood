---
paths:
  - '{app/Filament/Support/FiscalSettingsFields.php,app/Filament/Tenant/Pages/FiscalSettings.php,app/Filament/Resources/Tenants/**}'
---

# Tenants

## Segredos fiscais em formulário: campo vazio mantém, estado limpo após salvar, Section ligada a relação 1:1
Chave de API e segredo de webhook: `password()->revealable(false)->trim()`, `afterStateHydrated(state(null))`, `dehydrated(filled)` (vazio = manter o valor salvo) e, depois de salvar, ZERAR o valor digitado em `$this->data` (Page: no save(); central: `EditTenant::afterSave()`), senão o texto puro volta ao navegador a cada roundtrip. Painel central usa `Section::relationship('fiscalSettings')` nas DUAS seções (o Filament salva as seções da mesma relação juntas e cria a linha com tenant_id); tenant antigo não tem a linha, então regime/ambiente/série (NOT NULL com default) não são `required` ali e `withoutNullDefaults` tira null/vazio do create/save. O Filament não aplica `->default()` num fill() que já traz outras chaves: a Page do tenant preenche os defaults explicitamente no mount(). Regime não suportado: opção desabilitada + regra com mensagem (D-07). UF/cidade IBGE seguem o padrão do endereço; teste com `Http::preventStrayRequests()` + fake do IBGE.
