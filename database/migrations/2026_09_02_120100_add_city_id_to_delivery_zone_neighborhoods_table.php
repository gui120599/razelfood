<?php

use App\Models\City;
use App\Models\DeliveryZoneNeighborhood;
use App\Models\Neighborhood;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('delivery_zone_neighborhoods', function (Blueprint $table) {
            // Liga o bairro do setor à cidade real do catálogo global. `city`
            // (string normalizada) continua sendo a fonte da resolução de taxa
            // em ResolveDeliveryFee; `city_id` serve para o checkout listar
            // exatamente as cidades atendidas pelo tenant.
            $table->foreignId('city_id')->nullable()->after('delivery_zone_id')->constrained('cities')->nullOnDelete();
        });

        $rows = DeliveryZoneNeighborhood::withoutGlobalScopes()
            ->whereNull('city_id')
            ->whereNotNull('city')
            ->get(['id', 'city', 'neighborhood']);

        if ($rows->isEmpty()) {
            return;
        }

        // Uma consulta pra todas as cidades candidatas (agrupadas por nome
        // normalizado, que pode colidir entre estados).
        $citiesByName = City::query()
            ->whereIn('normalized_name', $rows->pluck('city')->unique())
            ->get(['id', 'normalized_name'])
            ->groupBy('normalized_name');

        // Uma consulta pra desambiguar homônimas: quais cidades candidatas
        // têm cada bairro.
        $ambiguousCityIds = $citiesByName->filter(fn ($group) => $group->count() > 1)->flatten()->pluck('id');
        $neighborhoodCityMap = $ambiguousCityIds->isEmpty()
            ? collect()
            : Neighborhood::query()
                ->whereIn('city_id', $ambiguousCityIds)
                ->whereIn('normalized_name', $rows->pluck('neighborhood')->unique())
                ->get(['city_id', 'normalized_name'])
                ->groupBy('normalized_name');

        foreach ($rows as $row) {
            $candidates = $citiesByName->get($row->city) ?? collect();

            $cityId = match (true) {
                $candidates->count() === 1 => $candidates->first()->id,
                $candidates->count() > 1 => (function () use ($candidates, $neighborhoodCityMap, $row) {
                    $ids = ($neighborhoodCityMap->get($row->neighborhood) ?? collect())
                        ->pluck('city_id')
                        ->intersect($candidates->pluck('id'))
                        ->unique();

                    return $ids->count() === 1 ? $ids->first() : null;
                })(),
                default => null,
            };

            if ($cityId !== null) {
                DeliveryZoneNeighborhood::withoutGlobalScopes()->whereKey($row->id)->update(['city_id' => $cityId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_zone_neighborhoods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
        });
    }
};
