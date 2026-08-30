<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('flash_promotion_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);          // preço efetivamente cobrado (já resolvido no servidor)
            $table->decimal('original_unit_price', 10, 2); // preço de tabela, para exibir desconto
            $table->string('note')->nullable();
            $table->json('flavors')->nullable();            // IDs de produtos combinados (RN-16), null se não aplicável

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
