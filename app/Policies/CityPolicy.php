<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\CentralPanelPolicy;

class CityPolicy
{
    use CentralPanelPolicy;

    protected function pricingSensitive(): bool
    {
        return false;
    }
}
