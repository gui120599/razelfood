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
        Schema::create('delivery_zone_neighborhoods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_zone_id')->constrained()->cascadeOnDelete();
            $table->string('neighborhood'); // bairro; normalizado na gravação (evita divergência de grafia com o retorno do ViaCEP)
            $table->string('city')->nullable(); // desambigua bairros de mesmo nome em cidades diferentes atendidas pelo mesmo tenant
            $table->timestamps();

            $table->index('tenant_id');
            // RN-35: um bairro pertence a no máximo um setor por tenant.
            // Observação: no MySQL, NULL não é considerado igual a outro NULL em índice único,
            // então dois registros com o mesmo bairro e city=null não seriam bloqueados por aqui —
            // a aplicação deve normalizar/validar isso na hora de salvar quando city não for informado.
            $table->unique(['tenant_id', 'neighborhood', 'city']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_zone_neighborhoods');
    }
};
