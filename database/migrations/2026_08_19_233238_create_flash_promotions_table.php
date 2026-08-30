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
        Schema::create('flash_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_recurring')->default(false);           // RN-17
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('weekdays')->nullable();                      // [0..6], vazio = todos os dias
            $table->time('start_time')->nullable();                    // suporta janela que cruza meia-noite
            $table->time('end_time')->nullable();
            $table->date('recurrence_end_date')->nullable();
            $table->timestamp('last_reset_at')->nullable();
            $table->unsignedInteger('total_quantity')->nullable();     // pool; null = sem teto (RN-18)
            $table->decimal('sold_quantity', 10, 2)->default(0);
            $table->unsignedInteger('per_order_limit')->nullable();    // RN-19
            $table->boolean('show_counter')->default(false);           // RN-20
            $table->unsignedInteger('scarcity_threshold')->nullable();
            $table->boolean('allows_flavors')->default(false);
            $table->unsignedTinyInteger('max_flavors')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flash_promotions');
    }
};
