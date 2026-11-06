<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Como a forma de pagamento aparece na NFC-e: código fiscal (nunca deduzido
     * pelo nome da forma), emissão automática e se bandeira/autorização são
     * obrigatórias no fechamento da conta.
     */
    public function up(): void
    {
        Schema::table('payment_options', function (Blueprint $table) {
            $table->string('fiscal_payment_code')->nullable(); // App\Enums\FiscalPaymentMethod
            $table->boolean('auto_issue_fiscal_document')->default(false);
            $table->boolean('requires_card_brand')->default(false);
            $table->boolean('requires_authorization_code')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_options', function (Blueprint $table) {
            $table->dropColumn(['fiscal_payment_code', 'auto_issue_fiscal_document', 'requires_card_brand', 'requires_authorization_code']);
        });
    }
};
