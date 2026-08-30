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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('show_in_menu')->default(true);
            $table->boolean('allows_flavors')->default(false);  // "meio a meio" (RN-16)
            $table->unsignedTinyInteger('max_flavors')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'show_in_menu']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
