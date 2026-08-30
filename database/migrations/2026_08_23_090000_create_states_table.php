<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('uf', 2);
            $table->unsignedInteger('ibge_code')->nullable();
            $table->timestamps();

            $table->unique('uf');
            $table->unique('ibge_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('states');
    }
};
