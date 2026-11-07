<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Desconto em reais POR LINHA da venda (campo "Desc. R$" da Nova Venda). Item avulso guarda na
     * própria linha; item de pedido guarda numa tabela da venda (não se mexe em `order_items`, que
     * é do pedido): a linha some junto com a reserva do item. `sale_items.total` segue sendo o valor
     * bruto (preço × quantidade); o líquido é total − discount_amount.
     */
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('discount_amount', 10, 2)->default(0)->after('total');
        });

        Schema::create('sale_order_item_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->unique(['sale_id', 'order_item_id']);
            $table->index(['tenant_id', 'sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_order_item_discounts');

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });
    }
};
