<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use App\Support\Orders\ResolveActiveShiftStart;
use App\Support\Reports\DeliveriesReport;
use App\Support\Reports\ReportPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Versão imprimível (A4) do relatório de entregas por entregador (RF-31),
 * aberta em nova aba a partir da página Entregas já autenticada.
 */
class DeliveriesReportPrintController extends Controller
{
    public function show(Request $request, DeliveriesReport $report): View
    {
        abort_unless(Auth::check(), 403);
        abort_unless(Auth::user()->canOperateInCurrentTenant(), 403);
        abort_unless(CurrentTenant::get()?->hasFeature(FeatureKey::RELATORIOS) ?? false, 403);
        abort_unless(Auth::user()->can('View:Deliveries'), 403);

        // `?shift=Y-m-d` (Central de Pedidos): o turno que abre nessa data, não o dia de calendário.
        $shifts = app(ResolveActiveShiftStart::class);
        $shiftDay = $shifts->parseOpeningDay($request->query('shift'));

        if ($shiftDay !== null) {
            [$from, $until] = $shifts->windowForOpeningDay($shiftDay);
            $groups = $report->groupsWithin($from, $until);
            $periodLabel = 'Turno de '.$shiftDay->format('d/m/Y').' ('.$from->format('d/m H:i').' a '.$until->format('d/m H:i').')';
        } else {
            [$start, $end] = ReportPeriod::resolveRange($request->query('start'), $request->query('end'));
            $groups = $report->groups($start, $end);
            $periodLabel = 'Período: '.$start->format('d/m/Y').' a '.$end->format('d/m/Y');
        }

        return view('reports.deliveries-print', [
            'tenant' => CurrentTenant::get(),
            'groups' => $groups,
            'periodLabel' => $periodLabel,
        ]);
    }
}
