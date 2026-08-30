<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\CentralPanelPolicy;

class PlanPolicy
{
    use CentralPanelPolicy;

    protected function pricingSensitive(): bool
    {
        return true;
    }
}
