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
        Schema::table('flavor_quantity_options', function (Blueprint $table) {
            $table->json('flavor_shares')->nullable()->after('flavor_count'); // % (0-100) de estoque/vendagem por posição de sabor, soma sempre 100
        });

        // Backfill: opções já cadastradas recebem o rateio igualitário como
        // ponto de partida editável (mesmo algoritmo do model, duplicado
        // aqui pra não depender de App\Models\FlavorQuantityOption mudar no
        // futuro e quebrar esta migration).
        DB::table('flavor_quantity_options')->orderBy('id')->each(function (object $option) {
            $count = max(1, (int) $option->flavor_count);
            $base = round(100 / $count, 2);
            $shares = array_fill(0, $count - 1, $base);
            $shares[] = round(100 - array_sum($shares), 2);

            DB::table('flavor_quantity_options')
                ->where('id', $option->id)
                ->update(['flavor_shares' => json_encode($shares)]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flavor_quantity_options', function (Blueprint $table) {
            $table->dropColumn('flavor_shares');
        });
    }
};
