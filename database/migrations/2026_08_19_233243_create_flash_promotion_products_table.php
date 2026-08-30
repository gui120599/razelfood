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
        Schema::create('flash_promotion_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); // confirmado: entra no isolamento multi-tenant como toda tabela de domínio
            $table->foreignId('flash_promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('promotional_price', 10, 2);
            $table->unsignedInteger('total_quantity')->nullable();   // sub-limite por produto dentro da promo
            $table->decimal('sold_quantity', 10, 2)->default(0);

            $table->index('tenant_id');
            $table->unique(['flash_promotion_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flash_promotion_products');
    }
};
