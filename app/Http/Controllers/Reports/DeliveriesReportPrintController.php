<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
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

        [$start, $end] = ReportPeriod::resolveRange($request->query('start'), $request->query('end'));

        return view('reports.deliveries-print', [
            'tenant' => CurrentTenant::get(),
            'groups' => $report->groups($start, $end),
            'periodLabel' => 'Período: '.$start->format('d/m/Y').' a '.$end->format('d/m/Y'),
        ]);
    }
}
