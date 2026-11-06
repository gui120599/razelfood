<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vínculo do pedido com a venda que o cobrou. Na Fase 1.0 a venda cobre
     * pedidos INTEIROS, então este campo e `order_items.sale_id` são escritos
     * juntos (só por FinalizeSale/CancelSale). Nulo = "a receber".
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('client_id')->constrained()->nullOnDelete();

            $table->index(['tenant_id', 'sale_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['sale_id']);
            $table->dropIndex(['tenant_id', 'sale_id']);
            $table->dropColumn('sale_id');
        });
    }
};
