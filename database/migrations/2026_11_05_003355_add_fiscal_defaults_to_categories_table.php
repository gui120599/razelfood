<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Padrão fiscal da categoria: os produtos herdam o que não preencherem
     * (produto → categoria → categoria-pai). Sem GTIN — código de barras é por produto.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('ncm', 8)->nullable();
            $table->string('cfop', 4)->nullable();
            $table->string('csosn', 3)->nullable();
            $table->string('origin_code', 1)->nullable();
            $table->string('commercial_unit', 6)->nullable();
            $table->string('cest', 7)->nullable();
            $table->decimal('icms_rate', 5, 2)->nullable();
            $table->decimal('pis_rate', 5, 2)->nullable();
            $table->decimal('cofins_rate', 5, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['ncm', 'cfop', 'csosn', 'origin_code', 'commercial_unit', 'cest', 'icms_rate', 'pis_rate', 'cofins_rate']);
        });
    }
};
