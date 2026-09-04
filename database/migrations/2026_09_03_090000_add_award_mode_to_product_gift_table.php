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
        Schema::table('product_gift', function (Blueprint $table) {
            // per_quantity (padrão, comportamento atual) = escala com a quantidade
            // da linha; per_order = uma única vez no pedido inteiro (RN-53).
            $table->string('award_mode', 20)->default('per_quantity')->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_gift', function (Blueprint $table) {
            $table->dropColumn('award_mode');
        });
    }
};
