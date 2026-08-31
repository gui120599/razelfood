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
        Schema::table('categories', function (Blueprint $table) {
            // Subcategoria pode optar por herdar as `flavor_quantity_options`
            // da categoria pai em vez de cadastrar as suas — ver
            // App\Models\Category::resolvedFlavorQuantityOptions().
            $table->boolean('inherit_flavor_options')->default(false)->after('allows_flavors');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('inherit_flavor_options');
        });
    }
};
