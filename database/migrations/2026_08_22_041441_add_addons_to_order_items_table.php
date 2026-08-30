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
            $table->json('addons')->nullable()->after('flavors');                 // [{addon_id, quantity, target}], null se não aplicável
            $table->decimal('addons_total', 10, 2)->default(0)->after('addons');  // custo total dos adicionais, por unidade (mesma convenção de unit_price)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['addons', 'addons_total']);
        });
    }
};
