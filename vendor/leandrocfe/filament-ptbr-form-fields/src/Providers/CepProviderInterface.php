<?php

namespace Leandrocfe\FilamentPtbrFormFields\Providers;

use Illuminate\Support\Collection;

interface CepProviderInterface
{
    public function fetch(string $cep): null|Collection|array;
}
