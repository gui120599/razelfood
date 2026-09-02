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
        Schema::create('product_gift', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gift_product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);   // unidades do brinde por unidade do produto principal
            $table->boolean('is_active')->default(true);        // liga/desliga a oferta sem remover o vínculo
            $table->json('flavor_counts')->nullable();          // [1, 2, ...] quantidades de sabores em que o brinde é oferecido; null = todas

            $table->index('tenant_id');
            $table->unique(['product_id', 'gift_product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_gift');
    }
};
