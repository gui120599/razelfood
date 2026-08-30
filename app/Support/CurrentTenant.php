<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Wrapper simples em volta do tenant resolvido na requisição atual.
 * Evita espalhar app(Tenant::class) por todo o código; um único ponto
 * de acesso deixa fácil trocar a estratégia de resolução no futuro.
 */
class CurrentTenant
{
    private static ?Tenant $tenant = null;

    public static function set(Tenant $tenant): void
    {
        static::$tenant = $tenant;
    }

    public static function get(): ?Tenant
    {
        return static::$tenant;
    }

    public static function id(): ?int
    {
        return static::$tenant?->id;
    }
}
