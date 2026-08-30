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
        // Snapshot estruturado do endereço de entrega no momento do pedido (não FK para
        // clients — preserva histórico se o cliente atualizar o cadastro depois), mesmos
        // campos de `clients`. A coluna `delivery_address` (texto livre) é mantida por ora
        // pelo mesmo motivo da migration de `clients`.
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_zone_id')->nullable()->after('delivery_option_id')
                ->constrained()->nullOnDelete(); // setor usado para resolver a taxa (RN-37); null se retirada, ou se bairro não configurado

            $table->string('delivery_zip_code', 9)->nullable()->after('delivery_address');
            $table->string('delivery_street')->nullable()->after('delivery_zip_code');
            $table->string('delivery_number', 20)->nullable()->after('delivery_street');
            $table->string('delivery_complement')->nullable()->after('delivery_number');
            $table->string('delivery_neighborhood')->nullable()->after('delivery_complement');
            $table->string('delivery_city')->nullable()->after('delivery_neighborhood');
            $table->string('delivery_state', 2)->nullable()->after('delivery_city');
            $table->boolean('is_unlisted_neighborhood')->default(false)->after('delivery_state'); // RN-37 caso 2 — sinaliza para o atendente confirmar viabilidade (RF-39)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_zone_id');
            $table->dropColumn([
                'delivery_zip_code', 'delivery_street', 'delivery_number', 'delivery_complement',
                'delivery_neighborhood', 'delivery_city', 'delivery_state', 'is_unlisted_neighborhood',
            ]);
        });
    }
};
