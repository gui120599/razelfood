<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use App\Support\Reports\ReportPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Versão imprimível (A4) da lista de pedidos do período da tela de
 * Relatórios (RF-31). Aberta em nova aba a partir da própria página, já
 * autenticada. Lê `start`/`end` da query string (o botão da página injeta
 * o período filtrado). Parâmetro de URI não se aplica aqui — mas o grupo
 * de domínio `{tenant}` continua valendo, daí ler tudo do request.
 */
class OrdersReportPrintController extends Controller
{
    public function show(Request $request): View
    {
        abort_unless(Auth::check(), 403);
        abort_unless(Auth::user()->tenant_id === CurrentTenant::id(), 403);
        abort_unless(CurrentTenant::get()?->hasFeature(FeatureKey::RELATORIOS) ?? false, 403);
        abort_unless(Auth::user()->can('View:Reports'), 403);

        [$start, $end] = ReportPeriod::resolveRange($request->query('start'), $request->query('end'));

        $orders = Order::query()
            ->with(['client', 'payments', 'deliveryOption'])
            ->openedBetween($start, $end)
            ->orderBy('opened_at')
            ->get();

        return view('reports.orders-print', [
            'tenant' => CurrentTenant::get(),
            'orders' => $orders,
            'periodLabel' => 'Período: '.$start->format('d/m/Y').' a '.$end->format('d/m/Y'),
        ]);
    }
}
