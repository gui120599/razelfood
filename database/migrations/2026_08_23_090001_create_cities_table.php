<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('state_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->unsignedInteger('ibge_code')->nullable();
            $table->timestamps();

            $table->unique('ibge_code');
            $table->unique(['state_id', 'normalized_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
