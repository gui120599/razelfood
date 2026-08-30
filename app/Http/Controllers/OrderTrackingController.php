<?php

namespace App\Http\Controllers;

use App\Actions\Orders\BuildOrderItemLines;
use App\Models\Order;
use App\Support\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class OrderTrackingController extends Controller
{
    /**
     * Lê o parâmetro direto do Request em vez de injeção por nome/type-hint
     * (route-model binding implícito). Dentro de Route::domain('{tenant}...'),
     * a injeção por nome do Laravel casa o parâmetro errado — o controller
     * recebe o valor do wildcard de domínio ({tenant}) em vez do parâmetro
     * de URI ({order}), mesmo o nome do parâmetro sendo diferente. Ler via
     * $request->route('order') explicitamente contorna isso.
     *
     * O parâmetro é o `public_token` opaco do pedido, não o id sequencial —
     * a URL é compartilhada com o cliente por WhatsApp e não pode ser
     * enumerável (RNF-07, LGPD). O token é gerado no `creating` de
     * App\Models\Order; quem monta o link (BuildWhatsAppMessage) passa
     * `$order->public_token` explicitamente.
     */
    public function show(Request $request): View
    {
        $order = Order::where('public_token', $request->route('order'))->firstOrFail();
        $order->load(['items.product.category', 'client', 'deliveryOption', 'payments']);

        return view('orders.tracking', [
            'order' => $order,
            'tenant' => CurrentTenant::get(),
            'itemLines' => app(BuildOrderItemLines::class)($order),
        ]);
    }
}
