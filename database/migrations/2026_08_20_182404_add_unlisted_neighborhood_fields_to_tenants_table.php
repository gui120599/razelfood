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
            $table->boolean('serves_unlisted_neighborhoods')->default(false)->after('recaptcha_secret_key'); // RN-36
            $table->decimal('unlisted_neighborhood_fee', 10, 2)->nullable()->after('serves_unlisted_neighborhoods'); // RN-36
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['serves_unlisted_neighborhoods', 'unlisted_neighborhood_fee']);
        });
    }
};
