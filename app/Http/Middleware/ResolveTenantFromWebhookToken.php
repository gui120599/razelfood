<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Webhooks\WebhookTenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve o tenant de um webhook externo pelo token opaco da URL
 * (`/webhooks/{provedor}/{token}`, MT-02) e roda o restante da requisição
 * dentro de `TenantContext` — o contexto é restaurado assim que a resposta é
 * montada, então nada vaza para a próxima requisição do mesmo worker.
 *
 * Uso: `webhook.tenant:{provedor}`, onde o provedor está registrado em
 * `config/webhooks.php`. Token malformado ou desconhecido → 404 sem gravar
 * nada (não confirma a existência de tokens). Tenant suspenso AINDA é
 * resolvido: o evento do provedor (ex.: pagamento) já aconteceu e quem decide
 * o que fazer é o controller; tenant excluído não resolve.
 *
 * Este middleware só identifica o tenant. Autenticar a chamada (HMAC, Basic
 * Auth) é do controller, DEPOIS de gravar o payload — ver
 * `App\Http\Controllers\Webhooks\TenantWebhookController`.
 */
class ResolveTenantFromWebhookToken
{
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{32,128}\z/';

    public function handle(Request $request, Closure $next, string $provider): Response
    {
        $token = $request->route('token');

        if (! is_string($token) || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            abort(404);
        }

        $tenant = $this->resolver($provider)->resolve($token);

        if (! $tenant instanceof Tenant || $tenant->trashed()) {
            Log::warning('Webhook com token desconhecido.', ['provider' => $provider, 'ip' => $request->ip()]);

            abort(404);
        }

        return TenantContext::run($tenant, fn (): Response => $next($request));
    }

    private function resolver(string $provider): WebhookTenantResolver
    {
        $resolverClass = config("webhooks.providers.{$provider}");

        if (! is_string($resolverClass) || ! is_a($resolverClass, WebhookTenantResolver::class, true)) {
            throw new LogicException("Provedor de webhook \"{$provider}\" sem resolver registrado em config/webhooks.php.");
        }

        return app($resolverClass);
    }
}
