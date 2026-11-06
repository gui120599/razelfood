<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sequência de numeração fiscal (MT-05): única por emitente (CNPJ) +
     * ambiente + modelo + série, ENTRE tenants — dois tenants com o mesmo CNPJ
     * compartilham a sequência. `tenant_id` guarda só o tenant de origem.
     */
    public function up(): void
    {
        Schema::create('fiscal_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('issuer_cnpj', 14);
            $table->string('environment', 20);
            $table->unsignedSmallInteger('model')->default(65);
            $table->unsignedSmallInteger('series');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['issuer_cnpj', 'environment', 'model', 'series'], 'fiscal_sequences_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_number_sequences');
    }
};
