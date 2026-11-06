<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quem pediu e quando foi pedido o cancelamento da NFC-e (a confirmação chega depois,
     * por webhook ou consulta, e preenche `cancelled_at`).
     */
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->timestamp('cancel_requested_at')->nullable()->after('authorized_at');
            $table->foreignId('cancel_requested_by_user_id')->nullable()->after('cancel_requested_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancel_requested_by_user_id');
            $table->dropColumn('cancel_requested_at');
        });
    }
};
