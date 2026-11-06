<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documento fiscal (NFC-e) de uma venda. O número é reservado ANTES do POST
     * e reaproveitado em reenvio enquanto não consumido (RN-FIS-01..03).
     * `provider_status` guarda o texto cru da NFE.io; `status` é o estado interno.
     * Documento fiscal é retido: tenant e venda não podem ser apagados com nota emitida (restrict).
     */
    public function up(): void
    {
        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('sequence_id')->constrained('fiscal_number_sequences')->restrictOnDelete();
            $table->string('environment', 20);
            $table->unsignedSmallInteger('model')->default(65);
            $table->unsignedSmallInteger('series');
            $table->unsignedInteger('number');
            $table->string('status', 30);
            $table->string('provider_status')->nullable();
            $table->string('provider_id')->nullable()->unique();
            $table->string('decision', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('error_message')->nullable();
            $table->string('buyer_omission_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['sequence_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');
    }
};
