<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dados fiscais do tenant (1:1), FORA de `tenants`: a tabela `tenants` é
     * cacheada inteira em `tenant:slug:*` e credenciais não podem ir para esse
     * cache (MT-04). CNPJ e endereço continuam em `tenants` (fonte única).
     * Os dois segredos usam o cast `encrypted` do model, então são `text`
     * (o payload criptografado passa de 255 caracteres).
     */
    public function up(): void
    {
        Schema::create('tenant_fiscal_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('legal_name')->nullable();
            $table->string('state_registration', 20)->nullable(); // IE: dígitos ou "ISENTO"
            $table->string('tax_regime')->default('simples_nacional'); // App\Enums\TaxRegime — v1 só Simples Nacional (D-07)
            $table->char('state_uf', 2)->nullable();
            $table->unsignedInteger('city_ibge_code')->nullable();

            $table->string('provider')->default('nfeio');
            $table->string('nfeio_company_id')->nullable();
            $table->text('nfeio_api_key')->nullable(); // encrypted
            $table->text('nfeio_webhook_secret')->nullable(); // encrypted
            $table->string('environment')->default('test'); // App\Enums\FiscalEnvironment

            $table->unsignedSmallInteger('nfce_series')->default(1);
            $table->decimal('approximate_tax_percent', 5, 2)->nullable();
            $table->timestamp('enabled_at')->nullable(); // emissão habilitada (exige FiscalReadiness ok)

            $table->timestamps();

            // O mesmo companyId não pode servir a dois tenants (a emissão e o webhook resolveriam a empresa errada).
            $table->unique(['provider', 'nfeio_company_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_fiscal_settings');
    }
};
