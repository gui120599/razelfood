<?php

namespace App\Support\Sales;

use App\Actions\Sales\SyncPdvActivation;
use App\Models\Tenant;
use App\Support\FeatureKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data de corte do PDV do tenant: pedidos anteriores a ela nunca entram em
 * "Contas a fechar" nem numa venda. Lê SEMPRE do banco (o Tenant do cache
 * `tenant:slug:*` pode estar defasado) e, se a feature está ligada mas a data
 * ainda não foi gravada (observer/comando ainda não rodou), grava na hora.
 * Tenant sem a feature devolve null (nenhum pedido elegível).
 */
final class PdvCutoff
{
    public static function for(Tenant $tenant): ?Carbon
    {
        $value = DB::table('tenants')->where('id', $tenant->id)->value('pdv_enabled_at');

        if ($value === null && $tenant->hasFeature(FeatureKey::PDV)) {
            app(SyncPdvActivation::class)($tenant);

            $value = DB::table('tenants')->where('id', $tenant->id)->value('pdv_enabled_at');
        }

        return $value === null ? null : Carbon::parse($value);
    }
}
