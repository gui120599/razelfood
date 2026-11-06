<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migration de DADOS: o Pizzaria-App tem Elo, Alelo e HiperCard (Elo é ~13% dos pagamentos
     * em cartão lá). Cria as três para os tenants que já existem; tenant novo recebe via
     * SeedDefaultTenantOptions. Idempotente.
     */
    /** Valores congelados: mudar o enum/rótulos no futuro não altera esta migration. */
    private const BRANDS = [
        'elo' => 'Elo',
        'alelo' => 'Alelo',
        'hipercard' => 'Hipercard',
    ];

    public function up(): void
    {
        $now = now();

        DB::table('tenants')->whereNull('deleted_at')->orderBy('id')->pluck('id')->each(function (int $tenantId) use ($now): void {
            foreach (self::BRANDS as $brand => $label) {
                $exists = DB::table('card_brands')
                    ->where('tenant_id', $tenantId)
                    ->where('brand', $brand)
                    ->where('name', $label)
                    ->exists();

                if (! $exists) {
                    DB::table('card_brands')->insert([
                        'tenant_id' => $tenantId,
                        'brand' => $brand,
                        'name' => $label,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    /**
     * Dados semeados não são removidos.
     */
    public function down(): void
    {
        //
    }
};
