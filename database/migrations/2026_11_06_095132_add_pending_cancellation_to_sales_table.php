<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cancelamento da venda aguardando a NFC-e: a venda segue Finalizada e estes campos
     * guardam o motivo e o usuário até a NFE.io confirmar o cancelamento da nota.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('pending_cancellation_note', 255)->nullable()->after('cancellation_note');
            $table->foreignId('pending_cancellation_by_user_id')->nullable()->after('pending_cancellation_note')->constrained('users')->nullOnDelete();
            $table->timestamp('pending_cancellation_at')->nullable()->after('pending_cancellation_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_cancellation_by_user_id');
            $table->dropColumn(['pending_cancellation_note', 'pending_cancellation_at']);
        });
    }
};
