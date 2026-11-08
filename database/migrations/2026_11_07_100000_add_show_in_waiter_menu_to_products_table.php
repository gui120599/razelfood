<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Mostrar no cardápio do garçom" (uso interno, como `produto_cardapio_garcom` no Pizzaria-App):
     * decide quais produtos as telas internas (Atender Pedido, Nova Venda, futuro painel do garçom)
     * oferecem, independente da visibilidade no cardápio público (`is_visible`). Produto novo
     * nasce true. Os que já existem herdam `is_visible`: assim as telas internas continuam
     * listando exatamente o que listavam até agora.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('show_in_waiter_menu')->default(true)->after('is_visible');
        });

        DB::table('products')->update(['show_in_waiter_menu' => DB::raw('is_visible')]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('show_in_waiter_menu');
        });
    }
};
