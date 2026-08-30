<?php

namespace App\Enums;

/**
 * Papel da equipe interna da Razel Tec no painel central (RN-44). Só tem
 * sentido para usuários com `tenant_id` nulo. O painel central não usa
 * Filament Shield (ver .ai/rules/resources.md) nem os papéis com "teams" do
 * Spatie (a pivot `model_has_roles.tenant_id` é NOT NULL) — este enum numa
 * coluna de `users` é a fonte de verdade, checada nas policies centrais
 * (App\Policies\Concerns\CentralPanelPolicy).
 */
enum CentralRole: string
{
    /** Acesso total: tenants, planos, catálogo de features, localidades. */
    case Platform = 'platform';

    /** Suporte aos clientes: tenants e localidades, mas não planos/features (precificação). */
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::Platform => 'Plataforma',
            self::Support => 'Suporte',
        };
    }

    /** Pode gerenciar planos e o catálogo de features (precificação). */
    public function managesPlansAndFeatures(): bool
    {
        return $this === self::Platform;
    }
}
