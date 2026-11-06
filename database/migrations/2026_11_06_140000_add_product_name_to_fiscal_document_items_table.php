<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nome do produto como estava na emissão, para o "Por produto" do relatório fiscal. A
     * `description` da nota agora leva categoria, frações de sabor e adicionais (como no
     * Pizzaria-App) e fragmentaria o agrupamento. Notas antigas ficam sem o nome e o
     * relatório cai na descrição.
     */
    public function up(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->string('product_name')->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->dropColumn('product_name');
        });
    }
};
