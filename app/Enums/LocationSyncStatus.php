<?php

namespace App\Enums;

enum LocationSyncStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Paused = 'paused';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Processing => 'Processando',
            self::Completed => 'Concluído',
            self::Paused => 'Pausado',
            self::Failed => 'Erro',
        };
    }
}
