---
paths:
  - 'resources/views/filament/tenant/livewire/orders/**'
---

# Tenant Livewire Orders

## Views de Atender Pedido: cópia do Pizzaria, contrato dos componentes intacto
As views de AttendOrder/catálogo/cliente/entrega são cópias traduzidas do Pizzaria-App (§2.4.9); nomes de eventos, props e computeds dos componentes tenant-order-* são contrato dos testes e não mudam.
Nunca usar `@php(...)` inline em Blade com `@php ... @endphp` depois dele: o regex do bloco engole o trecho entre os dois e gera erro de sintaxe. Use sempre a forma em bloco.
Selects com opção vazia ("Selecione...", "Escolha...") mandam "" no wire:change: os métodos devem aceitar int|string|null e tratar blank() como limpar. Ids vindos do select devem ser validados contra a lista exibida (tenant + visíveis).
O endereço do cliente só é enviado ao pedido quando a opção de entrega escolhida tem requires_address (o bloco some na retirada).
