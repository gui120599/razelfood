<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_sync_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('cep');
            $table->string('type');
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index('location_sync_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_sync_logs');
    }
};
