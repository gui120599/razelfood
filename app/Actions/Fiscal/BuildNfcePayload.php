<?php

namespace App\Actions\Fiscal;

use App\Enums\FiscalPaymentMethod;
use App\Exceptions\FiscalDocumentException;
use App\Models\Addon;
use App\Models\CardBrand;
use App\Models\FiscalDocument;
use App\Models\FlavorQuantityOption;
use App\Models\OrderItem;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Scopes\TenantScope;
use App\Models\TenantFiscalSettings;
use App\Support\Fiscal\BuyerResolver;
use App\Support\Fiscal\CentAllocator;
use App\Support\Fiscal\FlavorFraction;
use App\Support\Fiscal\NfcePayload;
use App\Support\Fiscal\ProductFiscalData;
use App\Support\Fiscal\ResolveProductFiscalData;
use App\Support\Sales\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Monta o corpo da NFC-e no formato da NFE.io (comprovado no Pizzaria-App). Sem
 * HTTP e sem escrita: lê a venda e devolve o payload ou recusa com uma mensagem
 * em pt-BR quando algo não fecha.
 *
 * Valores (RN-FIS-05..09): `unitAmount` é o preço unitário do item; `totalAmount`
 * = quantidade × preço; `othersAmount` leva os adicionais (× quantidade) mais a
 * fração do frete, rateado igualmente em centavos entre as linhas; o desconto do
 * pedido é só informativo (os preços já são líquidos), então `discountAmount` é 0.
 * Pizza de sabores é UM item. Brindes ficam fora da nota. A soma das linhas tem de
 * bater com o total da venda, e pagamentos − troco também, centavo a centavo.
 */
class BuildNfcePayload
{
    private const DESCRIPTION_MAX_LENGTH = 120;

    /** CSOSN sem destaque de ICMS. */
    private const CSOSN_WITHOUT_ICMS = ['102', '500'];

    public function __construct(
        private readonly ResolveProductFiscalData $resolveProductFiscalData,
        private readonly BuyerResolver $buyerResolver,
    ) {}

    /**
     * @throws FiscalDocumentException
     */
    public function __invoke(FiscalDocument $document, Sale $sale, TenantFiscalSettings $settings): NfcePayload
    {
        $lines = $this->buildLines($sale);

        if ($lines->isEmpty()) {
            throw new FiscalDocumentException('A venda não tem itens para emitir a NFC-e.');
        }

        $lines = $this->applyFreight($lines, Money::toCents($sale->delivery_fee));
        $totalCents = Money::toCents($sale->total);
        $itemsCents = $lines->sum(fn (array $line): int => $line['total_cents'] + $line['others_cents']);

        if ($itemsCents !== $totalCents) {
            throw new FiscalDocumentException('Os itens da venda ('.Money::format($itemsCents).') não fecham com o total da venda ('.Money::format($totalCents).'). Revise a venda antes de emitir.');
        }

        $payment = $this->buildPayment($sale, $totalCents);
        ['buyer' => $buyer, 'reason' => $reason] = ($this->buyerResolver)($sale);

        $body = [
            'id' => (string) $document->id,
            'serie' => $document->series,
            'number' => $document->number,
            'operationOn' => $sale->finalized_at->toJSON(),
            'operationNature' => 'Venda de mercadoria',
            'operationType' => 'Outgoing',
            'destination' => 'Internal_Operation',
            'purposeType' => 'Normal',
            'consumerType' => 'FinalConsumer',
            'presenceType' => 'Presence',
        ];

        // Chave sempre presente, `null` sem comprador (como o Pizzaria-App em produção).
        $body['buyer'] = $buyer;
        $body['payment'] = [$payment];
        $body['items'] = $lines->map(fn (array $line): array => $this->toItem($line, $settings))->values()->all();

        return new NfcePayload($body, $reason, $lines->values()->map(fn (array $line, int $index): array => $this->snapshot($line, $index + 1))->all());
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildLines(Sale $sale): Collection
    {
        $orderItems = OrderItem::query()->where('sale_id', $sale->id)->orderBy('order_id')->orderBy('id')->get();
        $saleItems = SaleItem::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $sale->tenant_id)->where('sale_id', $sale->id)->orderBy('id')->get();

        $productIds = $orderItems->flatMap(fn (OrderItem $item): array => [$item->product_id, ...($item->flavors ?? [])])
            ->merge($saleItems->pluck('product_id'))
            ->filter()
            ->unique()
            ->values();

        $products = Product::query()->withTrashed()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $sale->tenant_id)
            ->whereIn('id', $productIds)
            ->with(['category' => fn ($query) => $query->withoutGlobalScope(TenantScope::class), 'category.parent' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)])
            ->get()
            ->keyBy('id');

        $addonNames = $this->addonNames($sale, $orderItems);
        $lines = collect();

        foreach ($orderItems as $item) {
            $flavors = collect($item->flavors ?? [])->map(fn ($id): ?Product => $products->get((int) $id))->filter()->values();
            $fiscalProduct = $flavors->first() ?? $products->get($item->product_id)
                ?? throw new FiscalDocumentException('Um item da venda aponta para um produto que não existe mais.');

            $name = $flavors->count() > 1
                ? $this->flavorsWithFractions($sale, $fiscalProduct, $flavors)
                : $fiscalProduct->name;

            $quantity = (int) $item->quantity;

            $lines->push($this->line(
                $fiscalProduct,
                $this->describe($fiscalProduct, $name, collect($item->addons ?? [])->map(fn (array $selection): ?string => $addonNames->get((int) ($selection['addon_id'] ?? 0)))->filter()->all()),
                $quantity,
                Money::toCents($item->unit_price),
                Money::toCents($item->addons_total) * $quantity,
            ));
        }

        foreach ($saleItems as $item) {
            $product = $products->get($item->product_id)
                ?? throw new FiscalDocumentException("O item avulso \"{$item->product_name}\" aponta para um produto que não existe mais.");

            $quantity = (int) $item->quantity;
            $unitCents = Money::toCents($item->unit_price);

            if ($unitCents * $quantity !== Money::toCents($item->total)) {
                throw new FiscalDocumentException("O total do item avulso \"{$item->product_name}\" não fecha com preço × quantidade.");
            }

            $lines->push($this->line($product, $this->describe($product, $item->product_name, []), $quantity, $unitCents, 0));
        }

        return $lines;
    }

    /**
     * Descrição como no Pizzaria-App: categoria + produto (ou sabores com fração) + " Adic. nome"
     * por adicional. A limpeza ASCII e o teto de 120 caracteres vêm depois, em line().
     *
     * @param  list<string>  $addonNames
     */
    private function describe(Product $product, string $name, array $addonNames): string
    {
        return trim(($product->category?->name ?? '').' '.$name.collect($addonNames)->map(fn (string $addon): string => ' Adic. '.$addon)->implode(''));
    }

    /**
     * "1/2 CALABRESA / 1/2 MUSSARELA": a fração vem do rateio configurado na categoria
     * (ou na categoria pai, quando herda); sem opção cadastrada, divisão igual.
     *
     * @param  Collection<int, Product>  $flavors
     */
    private function flavorsWithFractions(Sale $sale, Product $fiscalProduct, Collection $flavors): string
    {
        $category = $fiscalProduct->category;
        $categoryId = $category?->inheritsFlavorOptions() ? $category->parent_id : $category?->id;

        $shares = $categoryId === null ? null : FlavorQuantityOption::query()->withTrashed()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $sale->tenant_id)
            ->where('category_id', $categoryId)
            ->where('flavor_count', $flavors->count())
            ->orderByRaw('deleted_at IS NULL DESC')
            ->orderBy('display_order')
            ->value('flavor_shares');

        $shares = is_array($shares) && count($shares) === $flavors->count() ? array_values($shares) : FlavorQuantityOption::equalShares($flavors->count());

        return $flavors->values()->map(fn (Product $flavor, int $index): string => FlavorFraction::label((float) $shares[$index]).' '.$flavor->name)->implode(' / ');
    }

    /**
     * @param  Collection<int, OrderItem>  $orderItems
     * @return Collection<int, string> id do adicional => nome
     */
    private function addonNames(Sale $sale, Collection $orderItems): Collection
    {
        $ids = $orderItems->flatMap(fn (OrderItem $item): array => array_column($item->addons ?? [], 'addon_id'))->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Addon::query()->withTrashed()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $sale->tenant_id)
            ->whereIn('id', $ids)
            ->pluck('name', 'id');
    }

    /**
     * @return array<string, mixed>
     */
    private function line(Product $product, string $description, int $quantity, int $unitCents, int $addonsCents): array
    {
        $fiscal = ($this->resolveProductFiscalData)($product);

        if (! $fiscal->isComplete()) {
            throw new FiscalDocumentException("O produto \"{$product->name}\" está sem dados fiscais (faltam: ".implode(', ', $fiscal->missing()).').');
        }

        if ($quantity < 1) {
            throw new FiscalDocumentException("A quantidade de \"{$product->name}\" é inválida.");
        }

        return [
            'product' => $product,
            'fiscal' => $fiscal,
            'description' => $this->sanitizeDescription($description),
            'category_name' => $this->categoryName($product),
            'quantity' => $quantity,
            'unit_cents' => $unitCents,
            'total_cents' => $unitCents * $quantity,
            'others_cents' => $addonsCents,
        ];
    }

    /**
     * Rateia o frete igualmente entre as linhas (em `othersAmount`, RN-FIS-07).
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFreight(Collection $lines, int $freightCents): Collection
    {
        if ($freightCents === 0) {
            return $lines;
        }

        $shares = CentAllocator::split($freightCents, $lines->count());

        return $lines->values()->map(function (array $line, int $index) use ($shares): array {
            $line['others_cents'] += $shares[$index];

            return $line;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayment(Sale $sale, int $totalCents): array
    {
        $payments = SalePayment::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $sale->tenant_id)->where('sale_id', $sale->id)->orderBy('id')->get();

        if ($payments->isEmpty()) {
            throw new FiscalDocumentException('A venda não tem pagamento registrado: a NFC-e exige a forma de pagamento.');
        }

        $options = PaymentOption::query()->withTrashed()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $sale->tenant_id)
            ->whereIn('id', $payments->pluck('payment_option_id')->filter())
            ->get()
            ->keyBy('id');

        $brands = CardBrand::query()->withTrashed()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $sale->tenant_id)
            ->whereIn('id', $payments->pluck('card_brand_id')->filter())
            ->get()
            ->keyBy('id');

        $details = [];
        $receivedCents = 0;
        $changeCents = 0;

        foreach ($payments as $payment) {
            $method = $options->get($payment->payment_option_id)?->fiscal_payment_code
                ?? throw new FiscalDocumentException("A forma de pagamento \"{$payment->payment_option_name}\" está sem código fiscal.");

            $detail = ['method' => $method->apiValue(), 'amount' => $this->amount(Money::toCents($payment->amount))];

            if (in_array($method, [FiscalPaymentMethod::CreditCard, FiscalPaymentMethod::DebitCard, FiscalPaymentMethod::InstantPayment], true)) {
                $detail['card'] = $this->cardBlock($payment, $brands->get($payment->card_brand_id));
            }

            $details[] = $detail;
            $receivedCents += Money::toCents($payment->amount);
            $changeCents += Money::toCents($payment->change_amount);
        }

        if ($receivedCents - $changeCents !== $totalCents) {
            throw new FiscalDocumentException('Os pagamentos da venda não fecham com o total (recebido − troco = '.Money::format($receivedCents - $changeCents).', total = '.Money::format($totalCents).').');
        }

        return ['paymentDetail' => $details, 'payback' => $this->amount($changeCents)];
    }

    /**
     * Bloco `card` (cartão e Pix), sempre com as quatro chaves, `null` quando não há o dado —
     * mesmo formato validado em produção no Pizzaria-App. O CNPJ da credenciadora vai como
     * texto (zero à esquerda). Sem integração com maquininha, o tipo é `notIntegrated`.
     *
     * @return array{federalTaxNumber: ?string, flag: ?string, authorization: ?string, integrationPaymentType: string}
     */
    private function cardBlock(SalePayment $payment, ?CardBrand $brand): array
    {
        $cnpj = preg_replace('/\D/', '', (string) $brand?->acquirer_cnpj);

        return [
            'federalTaxNumber' => $cnpj === '' ? null : $cnpj,
            'flag' => $brand?->brand->apiValue(),
            'authorization' => filled($payment->authorization_code) ? $payment->authorization_code : null,
            'integrationPaymentType' => 'notIntegrated',
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function toItem(array $line, TenantFiscalSettings $settings): array
    {
        /** @var ProductFiscalData $fiscal */
        $fiscal = $line['fiscal'];
        $unitAmount = $this->amount($line['unit_cents']);

        $item = [
            'code' => (string) $line['product']->id,
            'codeGTIN' => filled($fiscal->gtin) ? $fiscal->gtin : null,
            'description' => $line['description'],
            'ncm' => $fiscal->ncm,
            'cfop' => (int) $fiscal->cfop,
            'unit' => $fiscal->commercialUnit,
            'quantity' => $line['quantity'],
            'unitAmount' => $unitAmount,
            'totalAmount' => $this->amount($line['total_cents']),
            'unitTax' => $fiscal->commercialUnit,
            'quantityTax' => $line['quantity'],
            'taxUnitAmount' => $unitAmount,
            'discountAmount' => 0,
            'othersAmount' => $this->amount($line['others_cents']),
            'totalIndicator' => $line['total_cents'] + $line['others_cents'] > 0,
            'cest' => filled($fiscal->cest) ? $fiscal->cest : null,
        ];

        $item['tax'] = $this->taxBlock($fiscal, $line['total_cents'] + $line['others_cents'], $settings);

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function taxBlock(ProductFiscalData $fiscal, int $lineCents, TenantFiscalSettings $settings): array
    {
        if (in_array($fiscal->csosn, self::CSOSN_WITHOUT_ICMS, true)) {
            return ['icms' => [
                'origin' => $fiscal->originCode,
                'csosn' => $fiscal->csosn,
                'baseTax' => 0,
                'amount' => 0,
                'rate' => 0,
            ]];
        }

        $rate = (float) ($fiscal->icmsRate ?? 0);
        $approximate = (float) ($settings->approximate_tax_percent ?? 0);

        return [
            'totalTax' => $this->amount((int) round($lineCents * $approximate / 100)),
            'icms' => [
                'origin' => $fiscal->originCode,
                'baseTaxModality' => '3',
                'baseTax' => $this->amount($lineCents),
                'amount' => $this->amount((int) round($lineCents * $rate / 100)),
                'rate' => $rate,
                'csosn' => $fiscal->csosn,
            ],
        ];
    }

    /**
     * Linha como será gravada em fiscal_document_items (valores como foram enviados).
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function snapshot(array $line, int $position): array
    {
        /** @var ProductFiscalData $fiscal */
        $fiscal = $line['fiscal'];

        return [
            'position' => $position,
            'product_id' => $line['product']->id,
            'product_name' => Str::limit($line['product']->name, 250, ''),
            'description' => $line['description'],
            'category_name' => $line['category_name'] === null ? null : Str::limit($line['category_name'], 250, ''),
            'ncm' => $fiscal->ncm,
            'cfop' => $fiscal->cfop,
            'csosn' => $fiscal->csosn,
            'origin_code' => $fiscal->originCode,
            'unit' => $fiscal->commercialUnit === null ? null : mb_substr($fiscal->commercialUnit, 0, 6),
            'quantity' => $line['quantity'],
            'unit_amount' => Money::fromCents($line['unit_cents']),
            'total_amount' => Money::fromCents($line['total_cents']),
            'others_amount' => Money::fromCents($line['others_cents']),
        ];
    }

    /**
     * "Pai › Filha" (ou só o nome) da categoria do produto que define os dados fiscais da linha.
     */
    private function categoryName(Product $product): ?string
    {
        $category = $product->category;

        if ($category === null) {
            return null;
        }

        return $category->parent !== null ? $category->parent->name.' › '.$category->name : $category->name;
    }

    private function amount(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private function sanitizeDescription(string $description): string
    {
        $clean = Str::of($description)->ascii()->upper()->replaceMatches('/[^A-Z0-9 \/.,%\-]/', ' ')->squish()->limit(self::DESCRIPTION_MAX_LENGTH, '')->trim()->toString();

        return $clean === '' ? 'ITEM' : $clean;
    }
}
