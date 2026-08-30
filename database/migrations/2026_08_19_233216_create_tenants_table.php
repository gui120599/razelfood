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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // Nome comercial do estabelecimento
            $table->string('slug')->unique();              // Usado como subdomínio: {slug}.razelfood.com.br
            $table->enum('status', ['active', 'suspended', 'cancelled'])->default('active');
            $table->string('whatsapp_number', 20);          // Número que recebe os pedidos (RN-27)
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 7)->nullable(); // Cor de destaque do cardápio (RF-16)
            $table->boolean('recaptcha_enabled')->default(false); // RN-29
            $table->string('recaptcha_site_key')->nullable();
            $table->string('recaptcha_secret_key')->nullable();
            $table->timestamps();
            $table->softDeletes(); // Retenção pós-cancelamento (item em aberto #7 do doc de regras)

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
