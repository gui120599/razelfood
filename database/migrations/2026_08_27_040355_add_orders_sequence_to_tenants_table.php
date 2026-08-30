<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contador da sequência de número de pedido por tenant (RN — comanda impressa
     * mostra "COMANDA Nº X" contínuo por estabelecimento, sem reset diário).
     * Alocado sob lock em App\Actions\Orders\AllocateOrderNumber, sempre dentro
     * da transação de CreateOrderFromCart.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('orders_sequence')->default(0)->after('plan_id');
        });

        // Alinha o contador ao maior número já atribuído por tenant (o backfill de
        // orders.order_number roda na migration anterior, por timestamp).
        DB::table('tenants')->orderBy('id')->each(function (object $tenant): void {
            $max = (int) DB::table('orders')->where('tenant_id', $tenant->id)->max('order_number');
            DB::table('tenants')->where('id', $tenant->id)->update(['orders_sequence' => $max]);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('orders_sequence');
        });
    }
};
