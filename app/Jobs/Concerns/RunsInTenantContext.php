<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\SetsTenantContext;
use App\Support\Tenancy\TenantContext;
use Closure;

/**
 * Faz o job rodar dentro do contexto de um tenant (MT-01).
 *
 * Contrato: o job recebe o ID (nunca o model, para não serializar um tenant
 * defasado) por uma propriedade pública no construtor:
 *
 *     public function __construct(public readonly int $tenantId) {}
 *
 * O job precisa ser enfileirável (`ShouldQueue` + trait `Queueable`):
 * job middleware só roda pelo pipeline da fila (inclusive o driver `sync` e
 * `dispatchSync()` de um job `Queueable`). `dispatch_now()`/`Bus::dispatchNow()`
 * de um job NÃO enfileirável ignora o middleware e o job rodaria sem tenant.
 *
 * Se o job já define `middleware()` (ex.: `WithoutOverlapping`), não use esta
 * trait diretamente: inclua `...$this->tenantContextMiddleware()` no array
 * retornado, ANTES dos demais, para que o lock/limite também rode com o
 * tenant correto. A trait NÃO consegue impor isso: se a classe define o próprio
 * `middleware()`, o método da classe vence o da trait e o job roda sem tenant.
 *
 * `failed()` e listeners de `JobFailed` rodam FORA do middleware (sem tenant);
 * quem consultar models de domínio ali deve usar `$this->inTenantContext(...)`.
 */
trait RunsInTenantContext
{
    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return $this->tenantContextMiddleware();
    }

    /**
     * @return array<int, object>
     */
    protected function tenantContextMiddleware(): array
    {
        return [new SetsTenantContext];
    }

    /**
     * Executa um trecho no contexto do tenant do job (ex.: dentro de `failed()`).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function inTenantContext(Closure $callback): mixed
    {
        return TenantContext::run($this->tenantId, fn () => $callback());
    }
}
