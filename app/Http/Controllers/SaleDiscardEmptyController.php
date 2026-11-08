<?php

namespace App\Http\Controllers;

use App\Actions\Sales\DiscardEmptySale;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Recebe o `sendBeacon` da Nova Venda quando o operador sai da tela e descarta o rascunho SE ele
 * ainda estiver vazio. Nunca apaga venda com conteúdo ou que não esteja mais aberta (a Action
 * relê a venda sob lock); a venda é buscada com o escopo do tenant, então id de outro
 * estabelecimento simplesmente não existe. Sem corpo de resposta: o navegador não espera.
 */
class SaleDiscardEmptyController extends Controller
{
    public function __invoke(Request $request, DiscardEmptySale $discard): Response
    {
        abort_unless(Auth::check(), 403);
        abort_unless(Auth::user()->canOperateInCurrentTenant(), 403);
        abort_unless(CurrentTenant::get()?->hasFeature(FeatureKey::PDV) ?? false, 403);
        abort_unless(Auth::user()->can('manage_sales'), 403);

        $discard((int) $request->route('sale'));

        return response()->noContent();
    }
}
