<?php

namespace App\Filament\Tenant\Resources\Products\Pages;

use App\Filament\Tenant\Resources\Products\ProductResource;
use App\Support\Preferences\PersistsFilterPreferences;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    use PersistsFilterPreferences;

    protected static string $resource = ProductResource::class;

    private const FILTER_PREFERENCE_KEY = 'products.table_filters';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

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
