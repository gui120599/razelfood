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
        Schema::table('order_items', function (Blueprint $table) {
            // [{gift_product_id, quantity, accepted}] — inclui os recusados (accepted:false)
            // para a pizzaria saber que o benefício existia. null se o item não tinha
            // nenhum vínculo de brinde ativo aplicável. Nome resolvido ao vivo (padrão de `addons`).
            $table->json('gifts')->nullable()->after('addons_total');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('gifts');
        });
    }
};
