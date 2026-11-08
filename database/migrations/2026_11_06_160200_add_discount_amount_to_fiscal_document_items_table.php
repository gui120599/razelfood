<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Desconto rateado na linha, como foi enviado em `discountAmount` (a NFC-e da venda com
     * desconto manual). Notas anteriores não tiveram desconto: ficam com 0.
     */
    public function up(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->decimal('discount_amount', 10, 2)->default(0)->after('others_amount');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_document_items', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });
    }
};
