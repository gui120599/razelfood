<?php

namespace App\Http\Controllers;

use App\Actions\Orders\MarkOrderDelivered;
use App\Enums\OrderStatus;
use App\Exceptions\OrderTransitionException;
use App\Models\Order;
use App\Support\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * RF-28: entregador confirma a entrega sem login, via link assinado/QR code.
 * Uma única rota GET+POST protegida por middleware "signed" (routes/web.php)
 * — o form de confirmação reenvia a própria URL assinada completa, então o
 * POST também é validado (o middleware "signed" só olha path+query string,
 * nunca o método HTTP).
 *
 * Lê o parâmetro direto do Request em vez de injeção por nome/type-hint —
 * mesmo bug de Route::domain('{tenant}...') documentado em OrderTrackingController.
 */
class DeliveryConfirmationController extends Controller
{
    public function __invoke(Request $request): View
    {
        $order = Order::findOrFail($request->route('order'));
        $error = null;

        if ($request->isMethod('post') && $order->status !== OrderStatus::Delivered) {
            try {
                app(MarkOrderDelivered::class)($order);
            } catch (OrderTransitionException $e) {
                $error = $e->getMessage();
            }
        }

        return view('orders.delivery-confirmation', [
            'order' => $order,
            'tenant' => CurrentTenant::get(),
            'error' => $error,
        ]);
    }
}
