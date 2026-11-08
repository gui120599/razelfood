<?php

use App\Http\Controllers\DeliveryConfirmationController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\OrderTicketController;
use App\Http\Controllers\OrderTrackingController;
use App\Http\Controllers\Reports\DeliveriesReportPrintController;
use App\Http\Controllers\Reports\FiscalReportCsvController;
use App\Http\Controllers\Reports\FiscalReportPrintController;
use App\Http\Controllers\Reports\OrdersReportPrintController;
use App\Http\Controllers\SaleDiscardEmptyController;
use App\Http\Middleware\ResolveTenantFromPath;
use App\Livewire\Checkout;
use App\Livewire\Menu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Domínio único (razelfood.com.br). Rotas de sistema / marketing primeiro —
// os painéis Filament (/admin, /painel/{slug}) são registrados à parte pelos
// PanelProviders. O grupo {tenant} abaixo é praticamente um catch-all do
// primeiro segmento, então nada que precise de rota própria pode vir depois.
Route::get('/', [LandingController::class, 'index'])->name('landing');

// URLs antigas das telas de venda ("Fechar conta" e "Contas a fechar"), substituídas pela Nova Venda:
// só redirecionam, levando `?orders=` (a Nova Venda lança esses pedidos). Fora do grupo {tenant}: `painel`
// é slug reservado. Sem sessão, o painel pede o login normalmente.
Route::get('/painel/{tenant}/{legacyScreen}', function (Request $request, string $tenant) {
    $orders = (string) $request->query('orders', '');

    return redirect("/painel/{$tenant}/vendas/operar".($orders !== '' ? '?'.http_build_query(['orders' => $orders]) : ''));
})->where(['tenant' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'legacyScreen' => 'fechar-conta|contas-a-fechar'])->name('sales.legacy_redirect');

// Cardápio público + páginas de pedido do tenant — razelfood.com.br/{slug}/...
// A constraint exclui os segmentos reservados (config/tenancy.php) para que
// /admin, /painel/... etc. não sejam capturados como slug de tenant.
$reservedSlugPattern = collect(config('tenancy.reserved_slugs'))
    ->map(fn (string $slug): string => preg_quote($slug, '/'))
    ->implode('|');

Route::prefix('{tenant}')
    ->where(['tenant' => '(?!(?:'.$reservedSlugPattern.')$)[a-z0-9]+(?:-[a-z0-9]+)*'])
    ->middleware(ResolveTenantFromPath::class)
    ->group(function () {
        Route::get('/', Menu::class)->name('menu.index');
        Route::get('/checkout', Checkout::class)->name('checkout.index');
        Route::get('/acompanhar/{order}', [OrderTrackingController::class, 'show'])->name('order.tracking');
        // Comanda de cozinha imprimível — protegida por auth + permissão no controller.
        Route::get('/comanda/{order}', [OrderTicketController::class, 'show'])->name('order.ticket');
        // Versões imprimíveis (A4) dos relatórios — abertas em nova aba pelo painel,
        // período vem da query string, auth + permissão validadas no controller.
        Route::get('/relatorios/pedidos/imprimir', [OrdersReportPrintController::class, 'show'])->name('reports.orders.print');
        Route::get('/relatorios/entregas/imprimir', [DeliveriesReportPrintController::class, 'show'])->name('reports.deliveries.print');
        // Relatório fiscal (NFC-e): CSV e versão imprimível; feature + permissão validadas no controller.
        Route::get('/relatorios/fiscal/csv', FiscalReportCsvController::class)->name('reports.fiscal.csv');
        Route::get('/relatorios/fiscal/imprimir', FiscalReportPrintController::class)->name('reports.fiscal.print');
        // Nova Venda: ao sair da tela, o navegador avisa (sendBeacon) e o rascunho VAZIO é descartado.
        // Auth + feature + permissão no controller; só apaga venda aberta sem conteúdo.
        Route::post('/vendas/{sale}/descartar-vazia', SaleDiscardEmptyController::class)->name('sales.discard_empty');
        // GET+POST na mesma rota assinada (RF-28): o form de confirmação reenvia a
        // própria URL assinada, então o middleware "signed" valida os dois métodos.
        Route::match(['GET', 'POST'], '/entrega/{order}', DeliveryConfirmationController::class)
            ->name('delivery.confirmation')
            ->middleware('signed');
    });
