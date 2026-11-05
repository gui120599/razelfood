<?php

namespace App\Enums;

enum FiscalEnvironment: string
{
    case Test = 'test';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Test => 'Homologação (teste)',
            self::Production => 'Produção',
        };
    }
}
