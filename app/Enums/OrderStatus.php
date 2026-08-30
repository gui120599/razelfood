<?php

namespace App\Enums;

use App\Support\BrandColors;
use Filament\Support\Colors\Color;

enum OrderStatus: string
{
    case Started = 'started';
    case Open = 'open';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Started => 'Iniciado',
            self::Open => 'Pedido Aceito',
            self::Preparing => 'Em Preparação',
            self::Ready => 'Pronto Aguardando Entrega',
            self::InTransit => 'Em Transporte',
            self::Delivered => 'Entregue',
            self::Finished => 'Finalizado',
            self::Cancelled => 'Cancelado',
        };
    }

    /**
     * RN: retirada/consumo local pula a etapa "Em Transporte" e vai direto pra
     * Finalizado; entrega passa por Em Transporte antes de Entregue. O parâmetro
     * é "este pedido passa pela etapa Em Transporte" (Order::usesInTransitStage()),
     * que depende tanto do tipo de atendimento quanto da config do tenant.
     */
    public function next(bool $hasDelivery): ?self
    {
        return match ($this) {
            self::Started => self::Open,
            self::Open => self::Preparing,
            self::Preparing => self::Ready,
            self::Ready => $hasDelivery ? self::InTransit : self::Finished,
            self::InTransit => self::Delivered,
            default => null,
        };
    }

    public function canBeCancelled(): bool
    {
        return match ($this) {
            self::Started, self::Open, self::Preparing, self::Ready, self::InTransit => true,
            default => false,
        };
    }

    /**
     * RN-32: a etapa InTransit -> Delivered é ação típica do Entregador, com
     * permissão própria (mark_order_delivered) — não conta como "avançar"
     * genérico (manage_order_status), mesmo o próximo status existindo.
     */
    public function canAdvanceGenerically(): bool
    {
        return match ($this) {
            self::Started, self::Open, self::Preparing, self::Ready => true,
            default => false,
        };
    }

    /**
     * Conteúdo do pedido (itens/entrega/pagamento) nunca é editável depois
     * de cancelado — diferente de canBeCancelled(), que trata a transição
     * de status, não o conteúdo.
     */
    public function isEditableContentWise(): bool
    {
        return $this !== self::Cancelled;
    }

    /**
     * A partir de "Pronto" (inclusive) em diante, editar o conteúdo do
     * pedido exige a permissão edit_order_advanced_status — até
     * "Preparando", qualquer colaborador com acesso à tela de atendimento
     * pode editar livremente.
     */
    public function requiresAdvancedPermissionToEdit(): bool
    {
        return match ($this) {
            self::Ready, self::InTransit, self::Delivered, self::Finished => true,
            default => false,
        };
    }

    /**
     * Sufixo do componente Blade de ícone (heroicon-o-{icon}/heroicon-s-{icon})
     * — usado na linha do tempo pública (order-status-timeline.blade.php) e
     * em qualquer outro lugar que precise de um ícone por status.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Started => 'clock',
            self::Open => 'clipboard-document-check',
            self::Preparing => 'fire',
            self::Ready => 'shopping-bag',
            self::InTransit => 'truck',
            self::Delivered => 'home-modern',
            self::Finished => 'check-badge',
            self::Cancelled => 'x-circle',
        };
    }

    public function timestampColumn(): string
    {
        return match ($this) {
            self::Started => 'opened_at',
            self::Open => 'accepted_at',
            self::Preparing => 'preparing_at',
            self::Ready => 'ready_at',
            self::InTransit => 'in_transit_at',
            self::Delivered => 'delivered_at',
            self::Finished => 'finished_at',
            self::Cancelled => 'cancelled_at',
        };
    }

    /**
     * Mapeamento de marca por status (docs/identidade-visual-design-system.md,
     * seção 2.4). 'primary'/'success'/'danger' usam as cores já registradas
     * nos PanelProviders; os demais status usam Color::hex() diretamente
     * (não exige registrar tokens extras em ->colors()).
     */
    public function color(): string|array
    {
        return match ($this) {
            self::Started => Color::Slate,
            self::Open => Color::hex(BrandColors::TEAL_500),
            self::Preparing => Color::hex(BrandColors::AMBER_300),
            self::Ready => 'primary',
            self::InTransit => Color::hex(BrandColors::TEAL_300),
            self::Delivered, self::Finished => 'success',
            self::Cancelled => 'danger',
        };
    }
}
