<?php

namespace App\Enums;

enum OrderFulfillmentType: string
{
    case Delivery = 'delivery';
    case Pickup = 'pickup';
    case DineIn = 'dine_in';

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'Delivery',
            self::Pickup => 'Retirada',
            self::DineIn => 'Consumo local',
        };
    }
}
