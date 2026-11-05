<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot dos pedidos cobrados por uma venda. `orders.sale_id` é apagado
     * quando a venda é cancelada (o pedido volta a "a receber"); esta tabela
     * preserva QUAIS pedidos a venda cobrou e com quais valores, para auditoria.
     */
    public function up(): void
    {
        Schema::create('sale_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_label'); // "#12" — número exibido do pedido na época
            $table->string('order_status'); // App\Enums\OrderStatus na hora da venda
            $table->string('client_name')->nullable();
            $table->decimal('order_total', 10, 2);
            $table->timestamps();

            $table->index(['tenant_id', 'sale_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_orders');
    }
};
