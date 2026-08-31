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
        Schema::table('clients', function (Blueprint $table) {
            // Só os 11 dígitos (sem máscara) — normalizado em FindOrCreateClient
            // e no ClientForm via InputMasks::cpf(). Sem unique: FindOrCreateClient
            // casa o cliente por telefone, não por CPF.
            $table->string('cpf', 11)->nullable()->after('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('cpf');
        });
    }
};
