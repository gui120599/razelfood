<?php

use App\Http\Controllers\DeliveryConfirmationController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\OrderTicketController;
use App\Http\Controllers\OrderTrackingController;
use App\Http\Controllers\Reports\DeliveriesReportPrintController;
use App\Http\Controllers\Reports\OrdersReportPrintController;
use App\Http\Middleware\ResolveTenantFromPath;
use App\Livewire\Checkout;
use App\Livewire\Menu;
use Illuminate\Support\Facades\Route;

// Domínio único (razelfood.com.br). Rotas de sistema / marketing primeiro —
// os painéis Filament (/admin, /painel/{slug}) são registrados à parte pelos
// PanelProviders. O grupo {tenant} abaixo é praticamente um catch-all do
// primeiro segmento, então nada que precise de rota própria pode vir depois.
Route::get('/', [LandingController::class, 'index'])->name('landing');

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
        // GET+POST na mesma rota assinada (RF-28): o form de confirmação reenvia a
        // própria URL assinada, então o middleware "signed" valida os dois métodos.
        Route::match(['GET', 'POST'], '/entrega/{order}', DeliveryConfirmationController::class)
            ->name('delivery.confirmation')
            ->middleware('signed');
    });
