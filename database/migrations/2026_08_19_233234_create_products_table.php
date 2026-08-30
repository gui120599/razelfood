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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('promotional_price', 10, 2)->nullable();  // RN-14 (precedência #2)
            $table->timestamp('promo_starts_at')->nullable();
            $table->timestamp('promo_ends_at')->nullable();
            $table->boolean('is_visible')->default(true);              // RN-11
            $table->boolean('controls_stock')->default(false);         // RN-24
            $table->decimal('stock_quantity', 10, 2)->nullable();
            $table->boolean('show_when_out_of_stock')->default(false); // RN-11
            $table->boolean('bestseller_eligible')->default(false);    // RN-15
            $table->unsignedInteger('sales_count')->default(0);        // RN-15, recalculado por job/trigger na confirmação do pedido
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_visible']);
            $table->index(['tenant_id', 'bestseller_eligible', 'sales_count']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
