<?php

namespace App\Enums;

/**
 * Nunca persistido — sempre calculado em tempo real a partir dos campos
 * de FlashPromotion (RN-21). Ver FlashPromotion::computedStatus().
 */
enum FlashPromotionStatus: string
{
    case Inactive = 'inativa';
    case Scheduled = 'agendada';
    case Active = 'ativa';
    case SoldOut = 'esgotada';
    case Ended = 'encerrada';
    case WaitingWindow = 'aguardando_janela';

    public function label(): string
    {
        return match ($this) {
            self::Inactive => 'Inativa',
            self::Scheduled => 'Agendada',
            self::Active => 'Ativa',
            self::SoldOut => 'Esgotada',
            self::Ended => 'Encerrada',
            self::WaitingWindow => 'Aguardando janela',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Inactive => 'gray',
            self::Scheduled => 'info',
            self::Active => 'success',
            self::SoldOut => 'danger',
            self::Ended => 'gray',
            self::WaitingWindow => 'warning',
        };
    }
}
