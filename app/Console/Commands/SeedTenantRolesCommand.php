<?php

namespace App\Console\Commands;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Models\Tenant;
use Illuminate\Console\Command;

class SeedTenantRolesCommand extends Command
{
    protected $signature = 'tenant:seed-roles {tenant : ID ou slug do tenant}';

    protected $description = 'Semeia os 5 papéis padrão (Admin, Gerente, Atendente, Caixa, Entregador) para um tenant. Pode ser rodado de novo a qualquer momento para atualizar as permissões do papel Admin.';

    public function handle(SeedDefaultTenantRoles $seed): int
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

        $this->components->info("Papéis semeados para o tenant \"{$tenant->name}\" ({$tenant->slug}).");

        return self::SUCCESS;
    }
}
