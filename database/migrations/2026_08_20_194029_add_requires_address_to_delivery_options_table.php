<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Corrige a premissa errada de que toda DeliveryOption é "entrega":
        // tenants já cadastram opções como "Retirada" e "Comer no Local"
        // (delivery_option_id preenchido mesmo sem endereço) — sem esta
        // coluna, o checkout pedia endereço e tentava resolver taxa por
        // bairro pra essas opções também.
        Schema::table('delivery_options', function (Blueprint $table) {
            $table->boolean('requires_address')->default(true)->after('name');
        });

        // Backfill best-effort pelas opções já cadastradas — palavras-chave
        // comuns de retirada/consumo local. Não é garantia de acerto: o
        // Admin deve revisar cada opção existente no formulário depois de
        // migrar (RN-30).
        DB::table('delivery_options')
            ->where(function ($query) {
                $query->where('name', 'like', '%retirada%')
                    ->orWhere('name', 'like', '%retirar%')
                    ->orWhere('name', 'like', '%balc%')
                    ->orWhere('name', 'like', '%local%')
                    ->orWhere('name', 'like', '%mesa%')
                    ->orWhere('name', 'like', '%buscar%')
                    ->orWhere('name', 'like', '%pickup%');
            })
            ->update(['requires_address' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_options', function (Blueprint $table) {
            $table->dropColumn('requires_address');
        });
    }
};
