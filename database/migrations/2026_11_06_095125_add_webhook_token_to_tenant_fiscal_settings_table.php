<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Token opaco da URL do webhook da NFE.io (`/webhooks/nfeio/{token}`): identifica o
     * tenant ANTES de autenticar a chamada (a assinatura HMAC usa o segredo dele). Não é o slug.
     */
    public function up(): void
    {
        Schema::table('tenant_fiscal_settings', function (Blueprint $table) {
            $table->string('webhook_token', 64)->nullable()->unique()->after('nfeio_webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_fiscal_settings', function (Blueprint $table) {
            $table->dropUnique(['webhook_token']);
            $table->dropColumn('webhook_token');
        });
    }
};
