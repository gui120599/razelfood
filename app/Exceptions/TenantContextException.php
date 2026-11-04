<?php

namespace App\Exceptions;

use Exception;

/**
 * Falha ao estabelecer o contexto de tenant fora de uma requisição HTTP
 * (jobs, commands agendados, webhooks). Sem contexto o `TenantScope` vira
 * no-op e o código enxergaria todos os tenants (MT-01), por isso a falha é
 * sempre explícita — nunca "segue sem tenant".
 */
class TenantContextException extends Exception
{
    public static function missingTenantId(string $jobClass): self
    {
        return new self("O job {$jobClass} precisa declarar a propriedade pública \"tenantId\" (inteiro) para rodar em contexto de tenant.");
    }

    public static function webhookWithoutTenant(string $controllerClass): self
    {
        return new self("O controller {$controllerClass} só pode rodar atrás do middleware webhook.tenant (sem contexto de tenant).");
    }

    public static function tenantNotFound(int $tenantId): self
    {
        return new self("Tenant #{$tenantId} não encontrado ou excluído; o contexto de tenant não pôde ser estabelecido.");
    }
}
