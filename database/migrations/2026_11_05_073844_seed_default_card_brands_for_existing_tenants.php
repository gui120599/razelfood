<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migration de DADOS: cria as bandeiras padrão para os tenants que já
     * existem (tenant novo recebe via SeedDefaultTenantOptions). Idempotente.
     */
    /** Valores congelados: mudar o enum/rótulos no futuro não altera esta migration. */
    private const BRANDS = [
        'visa' => 'Visa',
        'mastercard' => 'Mastercard',
        'american_express' => 'American Express',
        'sorocred' => 'Sorocred',
        'other' => 'Outra',
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
     * Dados semeados não são removidos: a tabela some no rollback da migration de schema.
     */
    public function down(): void
    {
        //
    }
};
