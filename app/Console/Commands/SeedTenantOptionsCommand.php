<?php

namespace App\Console\Commands;

use App\Actions\Tenants\SeedDefaultTenantOptions;
use App\Models\Tenant;
use Illuminate\Console\Command;

class SeedTenantOptionsCommand extends Command
{
    protected $signature = 'tenant:seed-options {tenant : ID ou slug do tenant}';

    protected $description = 'Semeia opções básicas de entrega (Retirada, Entregar, Comer no local) e pagamento (Dinheiro, Cartão Débito, Cartão Crédito, Pix) para um tenant. Pode ser rodado de novo a qualquer momento sem duplicar.';

    public function handle(SeedDefaultTenantOptions $seed): int
    {
        $identifier = $this->argument('tenant');

        $tenant = is_numeric($identifier)
            ? Tenant::find($identifier)
            : Tenant::where('slug', $identifier)->first();

        if ($tenant === null) {
            $this->components->error("Tenant não encontrado: {$identifier}");

            return self::FAILURE;
        }

        $seed($tenant);

        $this->components->info("Opções de entrega e pagamento semeadas para o tenant \"{$tenant->name}\" ({$tenant->slug}).");

        return self::SUCCESS;
    }
}
