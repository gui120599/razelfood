<?php

namespace Leandrocfe\FilamentPtbrFormFields\Providers;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ViaCepProvider implements CepProviderInterface
{
    protected ?string $url = null;

    public function __construct(?string $url = null)
    {
        $this->url = $url ?? config('filament-ptbr-form-fields.viacep_url');
    }

    public function fetch(string $cep): null|Collection|array
    {
        $url = Str::of($this->url)
            ->append($cep)
            ->append('/json/');

        $response = Http::get($url)->json();

        if (blank($response) || Arr::has($response, 'erro')) {
            return null;
        }

        return $response;
    }
}
