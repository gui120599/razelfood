<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dados fiscais do produto (NFC-e, Simples Nacional). Todos nullable: o que
     * faltar aparece no checklist App\Support\Fiscal\FiscalReadiness e pode
     * herdar do padrão fiscal da categoria (ResolveProductFiscalData).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('ncm', 8)->nullable();
            $table->string('cfop', 4)->nullable();
            $table->string('csosn', 3)->nullable();
            $table->string('origin_code', 1)->nullable();
            $table->string('commercial_unit', 6)->nullable();
            $table->string('cest', 7)->nullable();
            $table->string('gtin', 14)->nullable();
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
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['ncm', 'cfop', 'csosn', 'origin_code', 'commercial_unit', 'cest', 'gtin', 'icms_rate', 'pis_rate', 'cofins_rate']);
        });
    }
};
