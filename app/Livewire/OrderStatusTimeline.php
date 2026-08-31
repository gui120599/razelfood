<?php

namespace App\Livewire;

use App\Livewire\Concerns\EstablishesTenantContext;
use App\Models\Order;
use Livewire\Component;

/**
 * RN-28: status "em tempo real" na página de acompanhamento do cliente.
 * Componente filho, montado com o Order já resolvido pelo controller (nunca
 * a partir da rota — ver bug documentado em OrderTrackingController).
 *
 * Fica restrito às colunas de status/timestamp do próprio Order — nunca
 * acessa relações (items, client, deliveryOption). O Livewire (ModelSynth)
 * reidrata o Model a cada poll sem os relacionamentos pré-carregados; tocar
 * neles aqui geraria uma consulta N+1 a cada wire:poll.
 */
class OrderStatusTimeline extends Component
{
    use EstablishesTenantContext;

    public Order $order;

    public function render()
    {
        return view('livewire.order-status-timeline');
    }
}
