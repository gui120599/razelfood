<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('state_id')->constrained();
            $table->foreignId('city_id')->constrained();
            $table->unsignedInteger('cep_start');
            $table->unsignedInteger('cep_end');
            $table->unsignedInteger('current_cep')->nullable();
            $table->unsignedInteger('last_confirmed_cep')->nullable();
            $table->unsignedInteger('total_ceps');
            $table->unsignedInteger('ceps_processed')->default(0);
            $table->unsignedInteger('ceps_valid')->default(0);
            $table->unsignedInteger('ceps_invalid')->default(0);
            $table->unsignedInteger('neighborhoods_found')->default(0);
            $table->unsignedInteger('neighborhoods_created')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('city_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_syncs');
    }
};
