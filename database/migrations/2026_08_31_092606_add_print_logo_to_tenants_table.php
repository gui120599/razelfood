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
        Schema::table('tenants', function (Blueprint $table) {
            // Logo dedicada às vias imprimíveis (comanda térmica e relatórios
            // A4). Só é renderizada quando `show_logo_on_prints` está ligada.
            $table->string('print_logo_path')->nullable()->after('favicon_path');
            $table->boolean('show_logo_on_prints')->default(false)->after('print_logo_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['print_logo_path', 'show_logo_on_prints']);
        });
    }
};
