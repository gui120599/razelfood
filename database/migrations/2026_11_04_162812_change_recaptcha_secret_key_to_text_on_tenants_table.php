<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * O cast `encrypted` grava base64 de JSON (iv + value + mac): mesmo uma
     * chave curta de ~40 caracteres vira ~250 caracteres, no limite do
     * VARCHAR(255). `text` evita truncar/estourar. Só schema — a criptografia
     * dos valores existentes é a migration seguinte (encrypt_recaptcha_...).
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('recaptcha_secret_key')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Rodar DEPOIS de reverter a migration de criptografia (o rollback do
     * Laravel já respeita essa ordem): com os valores de volta em texto puro
     * (≤ 255) o retorno para string(255) não trunca nada.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('recaptcha_secret_key')->nullable()->change();
        });
    }
};
