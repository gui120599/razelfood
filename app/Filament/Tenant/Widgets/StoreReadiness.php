<?php

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Pages\ManageBusinessHours;
use App\Filament\Tenant\Resources\DeliveryZones\DeliveryZoneResource;
use App\Filament\Tenant\Resources\PaymentOptions\PaymentOptionResource;
use App\Filament\Tenant\Resources\Products\ProductResource;
use App\Models\BusinessHour;
use App\Models\DeliveryZone;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use Filament\Widgets\Widget;

class StoreReadiness extends Widget
{
    protected string $view = 'filament.tenant.widgets.store-readiness';

    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = '2';

    public static function canView(): bool
    {
        return CurrentTenant::get()?->hasFeature(FeatureKey::CONFIGURACOES_ESTABELECIMENTO) ?? false;
    }

    /**
     * @return array<int, array{label: string, done: bool, url: string}>
     */
    public function checklist(): array
    {
        return [
            [
                'label' => 'Horário de funcionamento cadastrado',
                'done' => BusinessHour::exists(),
                'url' => ManageBusinessHours::getUrl(),
            ],
            [
                'label' => 'Zona de entrega cadastrada',
                'done' => DeliveryZone::exists(),
                'url' => DeliveryZoneResource::getUrl('index'),
            ],
            [
                'label' => 'Forma de pagamento ativa',
                'done' => PaymentOption::where('show_in_menu', true)->exists(),
                'url' => PaymentOptionResource::getUrl('index'),
            ],
            [
                'label' => 'Produto cadastrado',
                'done' => Product::exists(),
                'url' => ProductResource::getUrl('index'),
            ],
        ];
    }
}
