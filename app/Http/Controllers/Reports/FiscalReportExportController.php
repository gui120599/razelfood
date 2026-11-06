<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use App\Support\Fiscal\FiscalReportQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Base das exportações do Relatório fiscal (CSV e impressão): mesma autorização da página
 * (feature `nfe_emissao` + `View:FiscalReport`) e mesmos parâmetros por query string
 * (`start`, `end`, `groupings[]`).
 */
abstract class FiscalReportExportController extends Controller
{
    /**
     * @return array{0: FiscalReportQuery, 1: list<string>}
     */
    protected function build(Request $request): array
    {
        abort_unless(Auth::check(), 403);
        abort_unless(Auth::user()->canOperateInCurrentTenant(), 403);
        abort_unless(CurrentTenant::get()?->hasFeature(FeatureKey::NFE_EMISSAO) ?? false, 403);
        abort_unless(Auth::user()->can('View:FiscalReport'), 403);

        $start = $request->query('start');
        $end = $request->query('end');

        return [
            FiscalReportQuery::forPeriod((int) CurrentTenant::id(), is_string($start) ? $start : null, is_string($end) ? $end : null),
            FiscalReportQuery::dimensionsFrom((array) $request->query('groupings', [])),
        ];
    }
}
