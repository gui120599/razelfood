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
            // Minutos decorridos na etapa atual do pedido a partir dos quais a Central de Pedidos
            // sinaliza "atenção"/"atrasado" (seção 9 da spec de redesenho da Central de Pedidos).
            $table->unsignedSmallInteger('order_attention_after_minutes')->default(15)->after('recaptcha_secret_key');
            $table->unsignedSmallInteger('order_late_after_minutes')->default(30)->after('order_attention_after_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['order_attention_after_minutes', 'order_late_after_minutes']);
        });
    }
};
