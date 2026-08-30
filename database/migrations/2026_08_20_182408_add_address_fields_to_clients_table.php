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
        // Endereço estruturado (RN-33), preenchido via busca de CEP (ViaCEP) ou manualmente.
        // `neighborhood` é o campo usado para resolver o setor de entrega (RN-34, RN-37).
        // A coluna `address` (texto livre) é mantida por ora para não quebrar o fluxo de
        // checkout/cadastro atual — migrar os usos e removê-la fica para quando o checkout
        // passar a gravar os campos estruturados.
        Schema::table('clients', function (Blueprint $table) {
            $table->string('zip_code', 9)->nullable()->after('address');
            $table->string('street')->nullable()->after('zip_code');
            $table->string('number', 20)->nullable()->after('street');
            $table->string('complement')->nullable()->after('number');
            $table->string('neighborhood')->nullable()->after('complement');
            $table->string('city')->nullable()->after('neighborhood');
            $table->string('state', 2)->nullable()->after('city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['zip_code', 'street', 'number', 'complement', 'neighborhood', 'city', 'state']);
        });
    }
};
