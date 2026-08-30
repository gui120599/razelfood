<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Papel da equipe interna no painel central (RN-44). Só se aplica a
     * usuários da Razel Tec (`tenant_id` nulo). Ver App\Enums\CentralRole.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('central_role')->nullable()->after('tenant_id');
        });

        // Usuários internos já existentes (tenant_id nulo) viram "Plataforma"
        // — preserva o acesso total que tinham antes das policies centrais.
        DB::table('users')->whereNull('tenant_id')->update(['central_role' => 'platform']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('central_role');
        });
    }
};
