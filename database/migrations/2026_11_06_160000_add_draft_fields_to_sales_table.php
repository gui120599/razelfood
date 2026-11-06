<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Venda em rascunho (status `open`, D-08): o número só é alocado ao finalizar, então
     * `number` passa a aceitar nulo (a unique (tenant_id, number) continua valendo: o MySQL
     * permite vários nulos). A venda ganha cliente, desconto manual (percentual + valor
     * aplicado) e quem abriu o rascunho. `discount_total` segue sendo só o desconto
     * informativo das promoções dos pedidos; o desconto manual é `manual_discount_total`.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->change();
            $table->foreignId('client_id')->nullable()->after('status')->constrained('clients')->nullOnDelete();
            // true quando o operador escolheu (ou limpou) o cliente: aí os pedidos lançados não o mudam mais.
            $table->boolean('client_manual')->default(false)->after('client_id');
            $table->decimal('discount_percent', 5, 2)->nullable()->after('discount_total');
            $table->decimal('manual_discount_total', 10, 2)->default(0)->after('discount_percent');
            $table->foreignId('opened_by_user_id')->nullable()->after('finalized_by_user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Rascunhos e rascunhos cancelados não têm número: finalize ou apague antes de reverter.
            if (DB::table('sales')->whereNull('number')->exists()) {
                throw new RuntimeException('Há vendas sem número (rascunhos): finalize ou apague-as antes de reverter esta migration.');
            }

            $table->dropConstrainedForeignId('opened_by_user_id');
            $table->dropColumn(['manual_discount_total', 'discount_percent', 'client_manual']);
            $table->dropConstrainedForeignId('client_id');
            $table->unsignedInteger('number')->nullable(false)->change();
        });
    }
};
