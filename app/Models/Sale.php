<?php

namespace App\Models;

use App\Enums\SaleStatus;
use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Venda / fechamento de conta. Agrega itens de pedidos (`order_items.sale_id` é a fonte de
 * verdade: reserva em venda Aberta, cobrança em Finalizada; `orders.sale_id` só marca o pedido
 * cobrado por inteiro numa única venda), itens avulsos (`sale_items`) e os pagamentos recebidos
 * (`sale_payments`). Nasce como rascunho (`open`) ou, pelo fechamento direto, já Finalizada;
 * mudada só pelas Actions de App\Actions\Sales.
 */
class Sale extends TenantScopedModel
{
    protected $fillable = [
        'tenant_id',
        'number',
        'status',
        'client_id',
        'client_manual',
        'items_total',
        'discount_total',
        'discount_percent',
        'manual_discount_total',
        'delivery_fee',
        'total',
        'finalized_at',
        'finalized_by_user_id',
        'opened_by_user_id',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancellation_note',
        'pending_cancellation_note',
        'pending_cancellation_by_user_id',
        'pending_cancellation_at',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => SaleStatus::class,
            'client_manual' => 'boolean',
            'items_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'manual_discount_total' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'finalized_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'pending_cancellation_at' => 'datetime',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Pedidos cobrados na época da venda (snapshot; persiste após o cancelamento).
     */
    public function saleOrders(): HasMany
    {
        return $this->hasMany(SaleOrder::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function isOpen(): bool
    {
        return $this->status === SaleStatus::Open;
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }

    /**
     * Documento fiscal mais recente da venda (qualquer estado).
     */
    public function currentFiscalDocument(): HasOne
    {
        return $this->hasOne(FiscalDocument::class)->latestOfMany();
    }

    /**
     * Tem NFC-e viva (emitida, em processamento ou em cancelamento)? Bloqueia o cancelamento da venda.
     */
    public function hasLiveFiscalDocument(): bool
    {
        return $this->fiscalDocuments()->live()->exists();
    }

    /**
     * Vendas pela situação da NFC-e MAIS RECENTE (`none` = nunca teve documento). `$status` é um
     * valor de FiscalDocumentStatus ou `none`.
     */
    public function scopeFiscalStatus(Builder $query, string $status): Builder
    {
        if ($status === 'none') {
            return $query->whereDoesntHave('fiscalDocuments');
        }

        return $query->whereHas('currentFiscalDocument', fn (Builder $document): Builder => $document->where('status', $status));
    }

    /**
     * Cancelamento pedido, aguardando a NFE.io cancelar a NFC-e (a venda segue Finalizada).
     */
    public function hasPendingCancellation(): bool
    {
        return $this->pending_cancellation_at !== null;
    }

    /**
     * Cancelamento pendente recente (dentro da janela de polling da tela): a tela da venda
     * se atualiza sozinha até o cancelamento ser concluído.
     */
    public function isAwaitingPendingCancellation(): bool
    {
        return $this->status === SaleStatus::Finalized
            && $this->pending_cancellation_at !== null
            && $this->pending_cancellation_at->gte(now()->subMinutes(FiscalDocument::POLL_WINDOW_MINUTES * 4));
    }

    public function displayNumber(): string
    {
        if ($this->number !== null) {
            return '#'.$this->number;
        }

        return $this->status === SaleStatus::Open ? 'Rascunho' : "Rascunho #{$this->id}";
    }
}
