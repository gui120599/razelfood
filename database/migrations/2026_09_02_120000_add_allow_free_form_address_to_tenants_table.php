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
        Schema::table('tenants', function (Blueprint $table) {
            // Checkout público: ligado (default) = cliente digita cidade/bairro
            // em texto livre (comportamento atual). Desligado = cidade e bairro
            // viram listas (cidades limitadas aos setores de entrega; bairros da
            // base oficial), reduzindo erro de digitação. Config em OrderSettings.
            $table->boolean('allow_free_form_address')->default(true)->after('unlisted_neighborhood_fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('allow_free_form_address');
        });
    }
};
