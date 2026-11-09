<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use App\Support\Orders\ResolveActiveShiftStart;
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
        abort_unless(Auth::user()->canOperateInCurrentTenant(), 403);
        abort_unless(CurrentTenant::get()?->hasFeature(FeatureKey::RELATORIOS) ?? false, 403);
        abort_unless(Auth::user()->can('View:Reports'), 403);

        // `?shift=Y-m-d` (Central de Pedidos): o turno que abre nessa data, não o dia de calendário.
        $shifts = app(ResolveActiveShiftStart::class);
        $shiftDay = $shifts->parseOpeningDay($request->query('shift'));

        if ($shiftDay !== null) {
            [$from, $until] = $shifts->windowForOpeningDay($shiftDay);
            $periodLabel = 'Turno de '.$shiftDay->format('d/m/Y').' ('.$from->format('d/m H:i').' a '.$until->format('d/m H:i').')';
            $scope = fn ($query) => $query->openedWithin($from, $until);
        } else {
            [$start, $end] = ReportPeriod::resolveRange($request->query('start'), $request->query('end'));
            $periodLabel = 'Período: '.$start->format('d/m/Y').' a '.$end->format('d/m/Y');
            $scope = fn ($query) => $query->openedBetween($start, $end);
        }

        $orders = $scope(Order::query()->with(['client', 'payments', 'deliveryOption']))
            ->orderBy('opened_at')
            ->get();

        return view('reports.orders-print', [
            'tenant' => CurrentTenant::get(),
            'orders' => $orders,
            'periodLabel' => $periodLabel,
        ]);
    }
}
