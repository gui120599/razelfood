<?php

namespace App\Filament\Tenant\Resources\Orders\Pages;

use App\Filament\Tenant\Resources\Orders\OrderResource;
use App\Support\Preferences\PersistsFilterPreferences;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    use PersistsFilterPreferences;

    protected static string $resource = OrderResource::class;

    private const FILTER_PREFERENCE_KEY = 'orders.table_filters';

    public function bootedInteractsWithTable(): void
    {
        parent::bootedInteractsWithTable();

        $this->loadFilterPreferences(self::FILTER_PREFERENCE_KEY, ['tableFilters']);
        $this->tableDeferredFilters = $this->tableFilters;
        $this->getTableFiltersForm()->fill($this->tableFilters);
    }

    protected function handleTableFilterUpdates(): void
    {
        parent::handleTableFilterUpdates();

        $this->persistFilterPreferences(self::FILTER_PREFERENCE_KEY, ['tableFilters']);
    }
}
