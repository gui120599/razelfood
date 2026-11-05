<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data de corte do PDV: só pedidos criados a partir daqui entram em "Contas a
     * fechar" (o histórico anterior à ativação não vira "a receber"). Gravada por
     * App\Actions\Sales\SyncPdvActivation (observers + comando agendado);
     * nula = o tenant não tem a feature `pdv` ativa.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('pdv_enabled_at')->nullable()->after('sales_sequence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('pdv_enabled_at');
        });
    }
};
