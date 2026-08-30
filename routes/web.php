<?php

use App\Http\Controllers\DeliveryConfirmationController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\OrderTicketController;
use App\Http\Controllers\OrderTrackingController;
use App\Http\Controllers\Reports\DeliveriesReportPrintController;
use App\Http\Controllers\Reports\OrdersReportPrintController;
use App\Livewire\Checkout;
use App\Livewire\Menu;
use Illuminate\Support\Facades\Route;

// Cardápio público + painel do tenant — qualquer subdomínio sob razelfood.com.br
Route::domain('{tenant}.'.config('tenancy.base_domain'))->group(function () {
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
    // Painel Filament do tenant fica registrado separadamente via Panel Provider (seção 4.7)
});

// Domínio central — sem subdomínio de tenant (marketing, painel interno Razel Tec)
Route::domain(config('tenancy.base_domain'))->group(function () {
    Route::get('/', [LandingController::class, 'index'])->name('landing');
});
