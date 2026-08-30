<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Antes desta migration, "quantidade de sabores" era um único teto
 * (categories.max_flavors) — qualquer combinação entre 2 e o teto era
 * aceita. Isso não permitia comprar 1 sabor só (pizza inteira) a partir de
 * uma categoria com allows_flavors=true, e o teto era fixo, não uma lista
 * de opções que o tenant pudesse nomear.
 *
 * Este backfill preserva o comportamento anterior como um ponto de partida
 * editável: cria "Sabor único" (1) sempre que allows_flavors=true, e uma
 * opção "N sabores" para cada N de 2 até o max_flavors anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        $categories = DB::table('categories')
            ->where('allows_flavors', true)
            ->whereNull('deleted_at')
            ->get(['id', 'tenant_id', 'max_flavors']);

        $now = now();

        foreach ($categories as $category) {
            $rows = [
                [
                    'tenant_id' => $category->tenant_id,
                    'category_id' => $category->id,
                    'label' => 'Sabor único',
                    'flavor_count' => 1,
                    'display_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ];

            $max = (int) ($category->max_flavors ?? 2);

            for ($count = 2; $count <= max($max, 2); $count++) {
                $rows[] = [
                    'tenant_id' => $category->tenant_id,
                    'category_id' => $category->id,
                    'label' => $count === 2 ? 'Meio a meio' : "{$count} sabores",
                    'flavor_count' => $count,
                    'display_order' => $count - 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('flavor_quantity_options')->insert($rows);
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('max_flavors');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_flavors')->nullable()->after('allows_flavors');
        });

        $categories = DB::table('categories')->where('allows_flavors', true)->get(['id']);

        foreach ($categories as $category) {
            $max = DB::table('flavor_quantity_options')
                ->where('category_id', $category->id)
                ->max('flavor_count');

            DB::table('categories')->where('id', $category->id)->update([
                'max_flavors' => $max ?? 2,
            ]);
        }

        DB::table('flavor_quantity_options')->truncate();
    }
};
