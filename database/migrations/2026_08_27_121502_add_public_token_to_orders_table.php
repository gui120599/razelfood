<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Token público e opaco do pedido, usado só na URL de acompanhamento
     * (`/acompanhar/{public_token}`) — substitui o `orders.id` sequencial,
     * que era enumerável dentro do tenant e expunha nome/endereço/telefone
     * do cliente de qualquer pedido (RNF-07, LGPD). O `id` e o `order_number`
     * continuam sendo os identificadores internos/operacionais.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->ulid('public_token')->nullable()->unique()->after('order_number');
        });

        Order::withoutGlobalScopes()
            ->whereNull('public_token')
            ->orderBy('id')
            ->chunkById(200, function ($orders) {
                foreach ($orders as $order) {
                    $order->updateQuietly(['public_token' => (string) Str::ulid()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
