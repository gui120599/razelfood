<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aponta o pagamento para a bandeira cadastrada. `card_brand` (texto)
     * continua como snapshot do nome na época da venda.
     */
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->foreignId('card_brand_id')->nullable()->after('card_brand')->constrained('card_brands')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('card_brand_id');
        });
    }
};
