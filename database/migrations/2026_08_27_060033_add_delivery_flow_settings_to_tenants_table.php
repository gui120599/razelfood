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
            // Configurações de fluxo de entrega da Central de Pedidos: se o pedido de
            // entrega passa pela etapa "Em Transporte" e se o despacho exige escolher
            // um entregador. Padrão ligado — preserva o comportamento atual.
            $table->boolean('uses_in_transit_stage')->default(true)->after('order_late_after_minutes');
            $table->boolean('assigns_delivery_couriers')->default(true)->after('uses_in_transit_stage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['uses_in_transit_stage', 'assigns_delivery_couriers']);
        });
    }
};
