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
            // Exige CPF do cliente no checkout online público (não afeta a
            // Central de Pedidos). Config editável em OrderSettings.
            $table->boolean('require_client_cpf')->default(false)->after('assigns_delivery_couriers');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('require_client_cpf');
        });
    }
};
