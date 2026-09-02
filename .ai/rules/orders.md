---
paths:
  - 'app/Filament/Tenant/{Pages,Livewire}/Orders/**'
---

# Orders

## AttendOrder → FulfillmentPicker: total via #[Reactive], nunca dispatch de dehydrate()
`$this->dispatch()` chamado de dentro do método `dehydrate()` de um componente Livewire NÃO é capturado — `SupportEvents::dehydrate` colhe os eventos do store ANTES do método `dehydrate()` da classe rodar. O `AttendOrder` tinha um `dehydrate()` que fazia `dispatch('order-cart-total-changed', ...)` pro FulfillmentPicker: nunca funcionou (prefill do valor da forma de pagamento sempre em branco).

Correção (ago/2026): `FulfillmentPicker::$total` virou `#[Reactive]` e a `AttendOrder` passa `'total' => $this->grandTotalPreview` no `@livewire(...)` a cada render. Componente Livewire aninhado só re-renderiza junto do pai com `#[Reactive]` (ou wire:model). Como prop reativa muda de fora sem passar por updating/updated, o `autofillRemaining()` é reavaliado no `render()` do filho; ele retorna bool e só re-emite `order-fulfillment-changed` pro pai quando realmente preencheu uma linha (senão vira loop de request pai↔filho). `dehydrate()` foi removido da AttendOrder.

## FulfillmentPicker: valores das formas de pagamento acompanham o total dinamicamente
Set/2026: quando o total do pedido muda (item novo, endereço com taxa diferente), os valores já digitados são reajustados — `render()` compara `$this->total` com `$lastSyncedTotal` (public, começa em 0) e só nesse caso chama `applyTotalToAmounts()`: linha única → fixa no total; várias linhas com alguma em branco → `autofillRemaining()`; todas preenchidas → a última absorve a diferença. NUNCA roda durante a digitação (o `updated()` de `payments.*.amount` só chama `autofillRemaining()`), pra não brigar com o atendente. `mount()` chama `applyTotalToAmounts()` (não seta `lastSyncedTotal`, deixa o 1º render reconciliar).

Trap de teste: `#[Reactive] $total` NÃO pode ser mutado isolado — `->set('total', x)` em `Livewire::test(FulfillmentPicker::class)` lança `CannotMutateReactivePropException`. Testar recriando o componente com o novo `total` + `initial.payments`, ou via integração na `AttendOrder`.

## AttendOrder: erros de validação do save() são toast do Filament, não banner
Set/2026: `save()` não tem mais `public ?string $errorMessage` nem banner no topo do blade (ficava fora da viewport em página alta). Cada validação chama `notifyError()` → `Notification::make()->title($msg)->danger()->send()` (toast nativo do Filament, igual ao `->success()` do fim do fluxo). Testes: `->assertNotified()` + `->assertRedirect()`/`->assertNoRedirect()` em vez de `->assertSet('errorMessage', ...)`. Os modais `FlavorPickerModal`/`AddonPickerModal` mantêm o `errorMessage` interno (estão sempre visíveis quando abertos).
