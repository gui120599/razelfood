<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Itens da NFC-e COMO FORAM ENVIADOS à NFE.io (snapshot gravado na emissão): o relatório
     * fiscal agrupa por aqui, então sempre bate com a nota mesmo que produto/categoria mudem
     * depois. Valores em decimal(10,2); `others_amount` = adicionais + fração do frete.
     */
    public function up(): void
    {
        Schema::create('fiscal_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_document_id')->constrained('fiscal_documents')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 120);
            $table->string('category_name')->nullable();
            $table->string('ncm', 8)->nullable();
            $table->string('cfop', 4)->nullable();
            $table->string('csosn', 3)->nullable();
            $table->string('origin_code', 1)->nullable();
            $table->string('unit', 6)->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_amount', 10, 2);
            $table->decimal('total_amount', 10, 2);
            $table->decimal('others_amount', 10, 2)->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'fiscal_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_items');
    }
};
