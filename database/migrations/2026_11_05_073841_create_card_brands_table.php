<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bandeiras de cartão por tenant (Fase 1.2). `brand` é o valor do enum
     * App\Enums\CardBrand (o que a NFE.io entende); `name` é o rótulo exibido;
     * `acquirer_cnpj` é o CNPJ da credenciadora (bloco `card` da NFC-e).
     */
    public function up(): void
    {
        Schema::create('card_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('brand');
            $table->string('name');
            $table->string('acquirer_cnpj', 14)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'brand', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_brands');
    }
};
