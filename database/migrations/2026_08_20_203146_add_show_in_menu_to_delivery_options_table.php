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
        Schema::table('delivery_options', function (Blueprint $table) {
            $table->boolean('show_in_menu')->default(true)->after('requires_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_options', function (Blueprint $table) {
            $table->dropColumn('show_in_menu');
        });
    }
};
