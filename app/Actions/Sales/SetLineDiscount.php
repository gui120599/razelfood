<?php

namespace App\Actions\Sales;

use App\Exceptions\SaleException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleOrderItemDiscount;
use App\Support\CurrentTenant;
use App\Support\Sales\Money;
use App\Support\Sales\OpenSale;
use Illuminate\Support\Facades\DB;

/**
 * Desconto em reais de UMA linha do rascunho (campo "Desc. R$"): item avulso (`sale_items`) ou item
 * de pedido lançado na venda. Não passa do valor da linha; soma-se ao desconto percentual da venda
 * (que incide sobre o que sobra). Item de pedido: o pedido é travado primeiro (protocolo de lock) e
 * o desconto fica numa tabela da venda, sem mexer no pedido.
 */
class SetLineDiscount
{
    private const MAX_CENTS = 9_999_999_999;

    public function __construct(private readonly SyncSaleTotals $syncTotals) {}

    /**
     * @param  'standalone'|'order'  $kind
     *
     * @throws SaleException
     */
    public function __invoke(Sale $sale, string $kind, int $lineId, float|int|string|null $amount): Sale
    {
        if (! is_numeric($amount) || abs((float) $amount) >= 100_000_000) {
            throw new SaleException('Informe um desconto válido (zero ou maior).');
        }

        $cents = Money::toCents($amount);

        if ($cents < 0 || $cents > self::MAX_CENTS) {
            throw new SaleException('Informe um desconto válido (zero ou maior).');
        }

        if (! in_array($kind, ['standalone', 'order'], true)) {
            throw new SaleException('Item não encontrado nesta venda.');
        }

        // O pedido do item é lido ANTES da transação para poder travá-lo primeiro.
        $orderId = $kind === 'order'
            ? (Order::query()->whereKey(OrderItem::query()->whereKey($lineId)->value('order_id'))->value('id') ?? throw new SaleException('Item não encontrado nesta venda.'))
            : null;

        return DB::transaction(function () use ($sale, $kind, $lineId, $cents, $orderId): Sale {
            if ($orderId !== null) {
                Order::query()->whereKey($orderId)->lockForUpdate()->first();
            }

            $locked = OpenSale::lock($sale);

            if ($kind === 'order') {
                $item = OrderItem::query()->whereKey($lineId)->where('sale_id', $locked->id)->first()
                    ?? throw new SaleException('Item não encontrado nesta venda.');
                $gross = (Money::toCents($item->unit_price) + Money::toCents($item->addons_total)) * (int) $item->quantity;

                $this->assertWithinLine($cents, $gross);

                $cents === 0
                    ? SaleOrderItemDiscount::query()->where('sale_id', $locked->id)->where('order_item_id', $item->id)->delete()
                    : SaleOrderItemDiscount::query()->updateOrCreate(
                        ['sale_id' => $locked->id, 'order_item_id' => $item->id],
                        ['tenant_id' => CurrentTenant::id(), 'amount' => Money::fromCents($cents)],
                    );
            } else {
                $line = SaleItem::query()->where('sale_id', $locked->id)->whereKey($lineId)->first()
                    ?? throw new SaleException('Item não encontrado nesta venda.');

                $this->assertWithinLine($cents, Money::toCents($line->total));

                $line->update(['discount_amount' => Money::fromCents($cents)]);
            }

            return ($this->syncTotals)($locked);
        }, attempts: 3);
    }

    /**
     * @throws SaleException
     */
    private function assertWithinLine(int $discountCents, int $grossCents): void
    {
        if ($discountCents > $grossCents) {
            throw new SaleException('O desconto passa do valor do item ('.Money::format($grossCents).').');
        }
    }
}
