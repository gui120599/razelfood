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
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->enum('status_from', [
                'started', 'open', 'preparing', 'ready', 'in_transit', 'delivered', 'finished', 'cancelled',
            ])->nullable(); // nulo apenas na eventual linha de criação do pedido
            $table->enum('status_to', [
                'started', 'open', 'preparing', 'ready', 'in_transit', 'delivered', 'finished', 'cancelled',
            ]);

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // nulo = transição sem ator humano direto
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent(); // append-only, sem updated_at

            $table->index(['tenant_id', 'order_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
