<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CNPJ e endereço fiscal/comercial do estabelecimento. Todos opcionais:
     * tenants já publicados não têm esses dados e ninguém é forçado a
     * preencher retroativamente. `neighborhood` segue a convenção de
     * `clients` (mesmo nome/tipo) por consistência.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('cnpj', 18)->nullable()->after('slug');
            $table->string('zip_code', 9)->nullable()->after('cnpj');
            $table->string('street')->nullable()->after('zip_code');
            $table->string('number', 20)->nullable()->after('street');
            $table->string('complement')->nullable()->after('number');
            $table->string('neighborhood')->nullable()->after('complement');
            $table->string('city')->nullable()->after('neighborhood');
            $table->string('state', 2)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['cnpj', 'zip_code', 'street', 'number', 'complement', 'neighborhood', 'city', 'state']);
        });
    }
};
