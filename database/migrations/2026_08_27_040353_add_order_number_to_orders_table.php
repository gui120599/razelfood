<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Número do pedido visível ao estabelecimento (comanda impressa, painel).
     * Sequência contínua por tenant — orders.id continua sendo o identificador
     * interno/global. Backfill: numera 1..N os pedidos já existentes por tenant,
     * na ordem de criação (id).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('order_number')->nullable()->after('id');
        });

        DB::table('tenants')->orderBy('id')->pluck('id')->each(function (int $tenantId): void {
            $number = 0;

            DB::table('orders')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->pluck('id')
                ->each(function (int $orderId) use (&$number): void {
                    $number++;
                    DB::table('orders')->where('id', $orderId)->update(['order_number' => $number]);
                });
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unique(['tenant_id', 'order_number']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'order_number']);
            $table->dropColumn('order_number');
        });
    }
};
