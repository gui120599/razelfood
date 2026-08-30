<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_option_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('items_total', 10, 2);
            $table->decimal('discount_total', 10, 2)->default(0);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('grand_total', 10, 2);

            $table->enum('status', [
                'started', 'open', 'preparing', 'ready', 'in_transit', 'delivered', 'finished', 'cancelled',
            ])->default('started'); // seção 6.1 do doc de regras

            $table->enum('cancellation_reason', [
                'customer_gave_up', 'entry_error', 'product_unavailable', 'delay',
                'duplicate_test', 'payment_issue', 'address_out_of_area', 'other',
            ])->nullable(); // RN-31
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('delivery_address')->nullable();
            $table->string('payment_option_name')->nullable(); // snapshot do nome, não FK — preserva histórico se a opção for editada/removida depois
            $table->decimal('change_for', 10, 2)->nullable();

            $table->enum('origin', ['menu', 'staff', 'table'])->default('menu'); // origem do pedido (glossário)

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('in_transit_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
