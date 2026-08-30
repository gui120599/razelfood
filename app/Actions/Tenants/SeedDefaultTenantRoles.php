<?php

namespace App\Actions\Tenants;

use App\Filament\Tenant\Pages\Deliveries;
use App\Filament\Tenant\Pages\Kitchen;
use App\Filament\Tenant\Pages\Orders\AttendOrder;
use App\Filament\Tenant\Pages\OrderSettings;
use App\Filament\Tenant\Pages\Reports;
use App\Filament\Tenant\Resources\Orders\OrderResource;
use App\Filament\Tenant\Resources\ProductionLines\ProductionLineResource;
use App\Models\Tenant;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Semeia os 5 papéis do doc de regras (seção 3) para um tenant. Idempotente:
 * pode ser rodada de novo a qualquer momento (ex.: depois que um módulo
 * novo gerar novas permissões via shield:generate) sem duplicar papéis nem
 * apagar permissões que um Admin tenha atribuído manualmente aos papéis
 * que não são Admin — por isso os papéis não-Admin usam givePermissionTo
 * (aditivo), nunca syncPermissions (substitutivo).
 *
 * RN-32: Gerente/Atendente avançam e cancelam pedidos; Entregador só
 * confirma entrega. Os nomes de permissão do Resource/Page são resolvidos
 * via FilamentShield em runtime (nunca hardcoded — o formato depende de
 * config/filament-shield.php) e criados aqui se ainda não existirem, pra
 * este comando não depender de shield:generate já ter rodado antes.
 */
class SeedDefaultTenantRoles
{
    private const ROLES = ['Admin', 'Gerente', 'Atendente', 'Caixa', 'Entregador'];

    public function __invoke(Tenant $tenant): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->id);

        // Cache de permissões pode estar defasado se um `shield:generate`
        // acabou de rodar em outro processo (CACHE_STORE=database) — sem isto,
        // `Permission::findOrCreate` abaixo tenta reinserir uma permissão que
        // já existe e estoura UniqueConstraintViolationException.
        $registrar->forgetCachedPermissions();

        // FilamentShield::getResources()/getPages() descobrem recursos por
        // painel "atual" — fora de um request HTTP (ex.: rodando via Artisan)
        // não há painel atual, e essa descoberta volta vazia silenciosamente.
        if (Filament::getCurrentPanel() === null) {
            Filament::setCurrentPanel(Filament::getPanel('tenant'));
        }

        foreach (self::ROLES as $name) {
            Role::findOrCreate($name);
        }

        $manageOrderStatus = Permission::findOrCreate('manage_order_status');
        $markOrderDelivered = Permission::findOrCreate('mark_order_delivered');
        $editOrderAdvancedStatus = Permission::findOrCreate('edit_order_advanced_status');

        $orderResourcePermissions = FilamentShield::getResources()[OrderResource::class]['permissions'] ?? [];
        $viewOrders = collect([
            $orderResourcePermissions['viewAny']['key'] ?? null,
            $orderResourcePermissions['view']['key'] ?? null,
        ])
            ->filter()
            ->map(fn (string $name) => Permission::findOrCreate($name));

        $kitchenPagePermissions = FilamentShield::getPages()[Kitchen::class]['permissions'] ?? [];
        $kitchenAccess = ($kitchenPageKey = array_key_first($kitchenPagePermissions))
            ? Permission::findOrCreate($kitchenPageKey)
            : null;

        $orderSettingsPagePermissions = FilamentShield::getPages()[OrderSettings::class]['permissions'] ?? [];
        $orderSettingsAccess = ($orderSettingsPageKey = array_key_first($orderSettingsPagePermissions))
            ? Permission::findOrCreate($orderSettingsPageKey)
            : null;

        $attendOrderPagePermissions = FilamentShield::getPages()[AttendOrder::class]['permissions'] ?? [];
        $attendOrderAccess = ($attendOrderPageKey = array_key_first($attendOrderPagePermissions))
            ? Permission::findOrCreate($attendOrderPageKey)
            : null;

        $reportsPagePermissions = FilamentShield::getPages()[Reports::class]['permissions'] ?? [];
        $reportsAccess = ($reportsPageKey = array_key_first($reportsPagePermissions))
            ? Permission::findOrCreate($reportsPageKey)
            : null;

        $deliveriesPagePermissions = FilamentShield::getPages()[Deliveries::class]['permissions'] ?? [];
        $deliveriesAccess = ($deliveriesPageKey = array_key_first($deliveriesPagePermissions))
            ? Permission::findOrCreate($deliveriesPageKey)
            : null;

        $productionLinePermissions = FilamentShield::getResources()[ProductionLineResource::class]['permissions'] ?? [];
        $manageProductionLines = collect($productionLinePermissions)
            ->pluck('key')
            ->filter()
            ->map(fn (string $name) => Permission::findOrCreate($name));

        Role::findOrCreate('Admin')->syncPermissions(Permission::all());

        Role::findOrCreate('Gerente')->givePermissionTo(
            collect([$manageOrderStatus, $markOrderDelivered, $kitchenAccess, $orderSettingsAccess, $attendOrderAccess, $editOrderAdvancedStatus, $reportsAccess, $deliveriesAccess])
                ->merge($viewOrders)
                ->merge($manageProductionLines)
                ->filter()
        );

        Role::findOrCreate('Atendente')->givePermissionTo(
            collect([$manageOrderStatus, $kitchenAccess, $attendOrderAccess])->merge($viewOrders)->filter()
        );

        Role::findOrCreate('Entregador')->givePermissionTo(
            collect([$markOrderDelivered, $kitchenAccess])->filter()
        );

        $registrar->forgetCachedPermissions();
    }
}
