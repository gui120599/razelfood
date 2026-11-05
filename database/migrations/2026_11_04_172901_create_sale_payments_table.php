<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pagamentos RECEBIDOS de uma venda (diferente de `order_payments`, que é
     * a forma declarada no pedido). `amount` é o valor entregue; o troco
     * (só de forma em dinheiro) fica em `change_amount`, então o valor líquido
     * é `amount - change_amount`. Bandeira/autorização/terminal são opcionais
     * e ficam prontos para a Fase 2 (`payment_terminal_id` ainda sem FK).
     */
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_option_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_option_name'); // snapshot do nome
            $table->boolean('is_cash')->default(false); // snapshot de PaymentOption::is_cash

            $table->decimal('amount', 10, 2);
            $table->decimal('change_amount', 10, 2)->default(0);

            $table->string('card_brand')->nullable();
            $table->string('authorization_code')->nullable();
            $table->unsignedBigInteger('payment_terminal_id')->nullable(); // FK na Fase 2.1

            $table->timestamps();

            $table->index(['tenant_id', 'sale_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
