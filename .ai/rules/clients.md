---
paths:
  - '{app/Models/Client.php,app/Filament/Tenant/Resources/Clients/**}'
---

# Clients

## Cliente PF não guarda CNPJ/IE: invariante no model, não no formulário
Campo oculto do Filament (CNPJ/IE só aparecem para `person_type = company`) NÃO é desidratado: zerar o estado no formulário não limpa o banco ao trocar PJ→PF. A limpeza é um `saving` do model (`Client::booted`): `person_type === Individual` ⇒ `cnpj` e `state_registration` viram null. A seção fiscal do cliente só aparece com a feature `nfe_emissao`. IE normalizada por `App\Support\Fiscal\StateRegistration::normalize()` (ISENTO em maiúsculas ou só dígitos), igual aos dados fiscais do tenant. A 1.2 decide PF/PJ por `person_type`, nunca por `cnpj` preenchido.
