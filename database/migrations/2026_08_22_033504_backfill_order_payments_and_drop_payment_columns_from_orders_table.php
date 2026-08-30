<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Backfill: cada pedido existente tinha só 1 forma de pagamento
        // (payment_option_name/change_for) — vira 1 linha em order_payments,
        // com amount = grand_total do pedido (era o valor total coberto por
        // aquela única forma).
        DB::table('orders')
            ->whereNotNull('payment_option_name')
            ->orderBy('id')
            ->select('id', 'payment_option_name', 'change_for', 'grand_total', 'created_at', 'updated_at')
            ->chunkById(500, function ($orders) {
                $now = now();

                DB::table('order_payments')->insert($orders->map(fn ($order) => [
                    'order_id' => $order->id,
                    'payment_option_name' => $order->payment_option_name,
                    'is_cash' => $order->change_for !== null,
                    'amount' => $order->grand_total,
                    'change_for' => $order->change_for,
                    'created_at' => $order->created_at ?? $now,
                    'updated_at' => $order->updated_at ?? $now,
                ])->all());
            });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_option_name', 'change_for']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Best-effort: recria as colunas e preenche a partir da PRIMEIRA linha
     * de order_payments de cada pedido — pedidos com mais de 1 forma de
     * pagamento perdem as parcelas extras ao reverter (limitação esperada
     * de uma migration de dados; não há como colapsar N linhas em 1 coluna
     * sem perda).
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_option_name')->nullable()->after('delivery_address');
            $table->decimal('change_for', 10, 2)->nullable()->after('payment_option_name');
        });

        DB::table('order_payments')
            ->orderBy('order_id')
            ->orderBy('id')
            ->select('order_id', 'payment_option_name', 'change_for')
            ->get()
            ->unique('order_id')
            ->each(fn ($payment) => DB::table('orders')->where('id', $payment->order_id)->update([
                'payment_option_name' => $payment->payment_option_name,
                'change_for' => $payment->change_for,
            ]));
    }
};
