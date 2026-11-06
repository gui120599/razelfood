<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índice do Relatório fiscal e do contador da central: tenant + situação + data de autorização.
     */
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->index(['tenant_id', 'status', 'authorized_at'], 'fiscal_documents_report_index');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropIndex('fiscal_documents_report_index');
        });
    }
};
