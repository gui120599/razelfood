<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frete do pedido que ESTA venda cobrou (o frete de um pedido é cobrado uma vez só, na venda
     * em que o pedido entra primeiro). Também serve para devolver o frete ao tirar o último
     * item do pedido de um rascunho. Vendas antigas cobraram o pedido inteiro: backfill com o
     * frete do pedido.
     */
    public function up(): void
    {
        Schema::table('sale_orders', function (Blueprint $table) {
            $table->decimal('delivery_fee_charged', 10, 2)->default(0)->after('order_total');
        });

        DB::statement('UPDATE sale_orders so JOIN orders o ON o.id = so.order_id SET so.delivery_fee_charged = o.delivery_fee');
    }

    public function down(): void
    {
        Schema::table('sale_orders', function (Blueprint $table) {
            $table->dropColumn('delivery_fee_charged');
        });
    }
};
