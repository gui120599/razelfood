---
paths:
  - 'app/Filament/Tenant/{Pages,Livewire}/Orders/**'
---

# Orders

## AttendOrder → FulfillmentPicker: total via #[Reactive], nunca dispatch de dehydrate()
`$this->dispatch()` chamado de dentro do método `dehydrate()` de um componente Livewire NÃO é capturado — `SupportEvents::dehydrate` colhe os eventos do store ANTES do método `dehydrate()` da classe rodar. O `AttendOrder` tinha um `dehydrate()` que fazia `dispatch('order-cart-total-changed', ...)` pro FulfillmentPicker: nunca funcionou (prefill do valor da forma de pagamento sempre em branco).

Correção (ago/2026): `FulfillmentPicker::$total` virou `#[Reactive]` e a `AttendOrder` passa `'total' => $this->grandTotalPreview` no `@livewire(...)` a cada render. Componente Livewire aninhado só re-renderiza junto do pai com `#[Reactive]` (ou wire:model). Como prop reativa muda de fora sem passar por updating/updated, o `autofillRemaining()` é reavaliado no `render()` do filho; ele retorna bool e só re-emite `order-fulfillment-changed` pro pai quando realmente preencheu uma linha (senão vira loop de request pai↔filho). `dehydrate()` foi removido da AttendOrder.
