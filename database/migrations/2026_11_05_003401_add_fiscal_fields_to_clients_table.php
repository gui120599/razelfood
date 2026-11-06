<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comprador da NFC-e: pessoa física (CPF, já existente) ou jurídica (CNPJ,
     * IE) e e-mail opcional.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('person_type')->default('individual'); // App\Enums\PersonType
            $table->string('cnpj', 14)->nullable();
            $table->string('state_registration', 20)->nullable();
            $table->string('email')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['person_type', 'cnpj', 'state_registration', 'email']);
        });
    }
};
