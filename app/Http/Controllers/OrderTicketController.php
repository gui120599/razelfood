<?php

namespace App\Http\Controllers;

use App\Actions\Orders\BuildOrderItemLines;
use App\Models\Order;
use App\Support\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Comanda de cozinha imprimível (bobina térmica 80mm — o operador imprime
 * pelo próprio navegador). Diferente da página de acompanhamento
 * (OrderTrackingController), NÃO é pública: expõe endereço/observações da
 * operação. O operador abre a comanda a partir do painel já autenticado
 * (mesma sessão web, mesmo host).
 *
 * Lê o parâmetro direto do Request — mesmo bug de Route::domain('{tenant}...')
 * documentado em OrderTrackingController (.ai/rules/routes.md).
 */
class OrderTicketController extends Controller
{
    public function show(Request $request): View
    {
        abort_unless(Auth::check(), 403);
        abort_unless(Auth::user()->tenant_id === CurrentTenant::id(), 403);
        abort_unless(Auth::user()->can('manage_order_status'), 403);

        // TenantScope global garante 404 para pedido de outro tenant.
        $order = Order::findOrFail($request->route('order'));
        $order->load(['items.product.category', 'client', 'deliveryOption', 'payments']);

        return view('orders.ticket', [
            'order' => $order,
            'tenant' => CurrentTenant::get(),
            'itemLines' => app(BuildOrderItemLines::class)($order),
        ]);
    }
}
