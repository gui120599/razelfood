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
            // Altura (px) da logo usada como marca d'água de fundo no cardápio
            // público (x-layouts.public) — a imagem não é mais limitada à
            // largura estreita da coluna de produtos, só a altura é configurável;
            // a largura acompanha a proporção natural da logo.
            $table->unsignedSmallInteger('watermark_height')->default(288)->after('primary_color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('watermark_height');
        });
    }
};
