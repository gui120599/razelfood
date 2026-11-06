<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contador da sequência de número de venda por tenant, alocado sob lock em
     * App\Actions\Sales\AllocateSaleNumber (mesmo padrão de `orders_sequence`).
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('sales_sequence')->default(0)->after('orders_sequence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('sales_sequence');
        });
    }
};
