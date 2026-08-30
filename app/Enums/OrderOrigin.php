<?php

namespace App\Enums;

enum OrderOrigin: string
{
    case Menu = 'menu';
    case Staff = 'staff';
    case Table = 'table';

    public function label(): string
    {
        return match ($this) {
            self::Menu => 'Cardápio',
            self::Staff => 'Atendente',
            self::Table => 'Mesa',
        };
    }
}
