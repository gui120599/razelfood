<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DESCRIPTION = 'Emissão de nota fiscal ao consumidor (NFC-e) via NFE.io: emissão, webhook, reconciliação, cancelamento e reenvio.';

    /**
     * Migration de DADOS: libera a feature `nfe_emissao` (ciclo de vida fiscal completo na Fase 1.3).
     * ATENÇÃO: o plano "Completo" lista TODAS as features (PlanSeeder), então tenants nesse plano
     * passam a ter a feature (telas fiscais) assim que a migration roda; a emissão continua exigindo
     * credenciais e a habilitação explícita em Dados fiscais. Em banco novo a linha ainda não
     * existe (o FeatureSeeder cria).
     */
    public function up(): void
    {
        DB::table('features')->where('key', 'nfe_emissao')->update(['is_available' => true, 'description' => self::DESCRIPTION, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('features')->where('key', 'nfe_emissao')->update(['is_available' => false, 'updated_at' => now()]);
    }
};
