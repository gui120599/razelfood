<?php

namespace App\Support\Preferences;

use App\Models\UserPreference;

/**
 * Carrega/salva o estado de filtros de um componente Livewire (ou página
 * Filament) em `user_preferences`, por nome de propriedade — funciona tanto
 * pra propriedades escalares simples quanto pra uma única propriedade array
 * (ex.: `tableFilters` do Filament), sem precisar saber o tipo.
 */
trait PersistsFilterPreferences
{
    /**
     * @param  array<int, string>  $properties
     * @param  array<int, string>  $skipIfAlreadySet  propriedades que só recebem o valor salvo se ainda estiverem no default (null) — usado quando outra fonte (ex.: querystring) tem prioridade
     */
    protected function loadFilterPreferences(string $key, array $properties, array $skipIfAlreadySet = []): void
    {
        $saved = UserPreference::valueFor(auth()->user(), $key);

        foreach ($properties as $property) {
            if (! array_key_exists($property, $saved)) {
                continue;
            }

            if (in_array($property, $skipIfAlreadySet, true) && $this->{$property} !== null) {
                continue;
            }

            $this->{$property} = $saved[$property];
        }
    }

    /**
     * @param  array<int, string>  $properties
     */
    protected function persistFilterPreferences(string $key, array $properties): void
    {
        UserPreference::rememberFor(
            auth()->user(),
            $key,
            collect($properties)->mapWithKeys(fn (string $property) => [$property => $this->{$property}])->all(),
        );
    }
}
