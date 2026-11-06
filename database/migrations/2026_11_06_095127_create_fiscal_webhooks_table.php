<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoria dos webhooks da NFE.io (append-only): o payload é gravado ANTES de
     * decidir se a chamada é autêntica. `signature_valid` nulo = o tenant não tem segredo
     * configurado. `fiscal_document_id` fica nulo quando o documento não é do tenant/não existe.
     */
    public function up(): void
    {
        Schema::create('fiscal_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('event')->nullable();
            $table->string('provider_id')->nullable();
            $table->foreignId('fiscal_document_id')->nullable()->constrained('fiscal_documents')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->boolean('signature_valid')->nullable();
            $table->string('outcome', 30)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'provider_id']);
            $table->index(['tenant_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_webhooks');
    }
};
