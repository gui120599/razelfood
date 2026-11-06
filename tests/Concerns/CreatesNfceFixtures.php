<?php

namespace Tests\Concerns;

use App\Actions\Sales\FinalizeSale;
use App\Enums\CardBrand as CardBrandEnum;
use App\Enums\FiscalPaymentMethod;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\PersonType;
use App\Models\CardBrand;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\TenantFiscalSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fixtures da Fase 1.2 (NFC-e): tenant com PDV + emissão, dados fiscais habilitados,
 * produtos fiscais completos, pedidos com adicionais/frete e venda finalizada.
 * Usar com CreatesSaleFixtures, CreatesFiscalFixtures e CreatesTenantWithFeatures.
 */
trait CreatesNfceFixtures
{
    /** CPF válido. */
    protected const VALID_CPF = '52998224725';

    /**
     * Tenant com PDV + nfe_emissao, endereço/CNPJ e dados fiscais habilitados. Deixa o contexto ativo.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function makeNfceTenant(string $slug, array $settings = [], string $cnpj = self::VALID_CNPJ): Tenant
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => 'active',
            'whatsapp_number' => '5511999999999',
            'plan_id' => $this->planWithPdvAndNfe()->id,
            'cnpj' => $cnpj,
            'zip_code' => '01310100',
            'street' => 'Avenida Paulista',
            'number' => '1000',
            'neighborhood' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
        ]);

        // Data de corte do PDV fixa e anterior aos pedidos do teste (não é o que se testa aqui):
        // evita depender de tenant e pedido caírem no mesmo segundo.
        DB::table('tenants')->where('id', $tenant->id)->update(['pdv_enabled_at' => now()->subMinutes(5)]);
        Cache::forget("tenant:slug:{$slug}");

        $this->completeFiscalSettings($tenant, array_merge(['enabled_at' => now()], $settings));
        $this->actAsTenant($tenant);

        return $tenant;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeFiscalProduct(Tenant $tenant, string $name = 'Calabresa', float $price = 40, array $attributes = []): Product
    {
        return $this->makeProduct($tenant, $name, $price, array_merge($this->completeProductFiscalData(), $attributes));
    }

    protected function makeFiscalPaymentOption(Tenant $tenant, string $name, FiscalPaymentMethod $code, bool $isCash = false, bool $auto = false): PaymentOption
    {
        return PaymentOption::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'is_cash' => $isCash,
            'show_in_menu' => true,
            'fiscal_payment_code' => $code,
            'auto_issue_fiscal_document' => $auto,
        ]);
    }

    protected function makeCardBrand(Tenant $tenant, CardBrandEnum $brand = CardBrandEnum::Visa, ?string $acquirerCnpj = '11222333000181'): CardBrand
    {
        return CardBrand::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'brand' => $brand->value, 'name' => $brand->label()],
            ['acquirer_cnpj' => $acquirerCnpj],
        );
    }

    /**
     * Pedido com itens: cada item `[product, quantity?, unit_price?, addons_total?, flavors?, gifts?]`.
     * items_total = Σ (preço + adicionais) × quantidade; grand_total = itens + taxa.
     *
     * @param  list<array<string, mixed>>  $items
     */
    protected function makeNfceOrder(Tenant $tenant, array $items, float $deliveryFee = 0, ?Client $client = null): Order
    {
        $itemsTotal = 0.0;

        foreach ($items as $item) {
            $itemsTotal += (($item['unit_price'] ?? $item['product']->price) + ($item['addons_total'] ?? 0)) * ($item['quantity'] ?? 1);
        }

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'client_id' => $client?->id,
            'items_total' => $itemsTotal,
            'delivery_fee' => $deliveryFee,
            'grand_total' => $itemsTotal + $deliveryFee,
            'status' => OrderStatus::Delivered,
            'origin' => OrderOrigin::Staff,
            'opened_at' => now(),
        ]);

        foreach ($items as $item) {
            $price = $item['unit_price'] ?? $item['product']->price;

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item['product']->id,
                'quantity' => $item['quantity'] ?? 1,
                'unit_price' => $price,
                'original_unit_price' => $price,
                'addons_total' => $item['addons_total'] ?? 0,
                'flavors' => $item['flavors'] ?? null,
                'gifts' => $item['gifts'] ?? null,
            ]);
        }

        return $order;
    }

    protected function makeNfceClient(Tenant $tenant, array $attributes = []): Client
    {
        return Client::create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => 'Maria da Silva',
            'phone' => '119'.random_int(10000000, 99999999),
            'person_type' => PersonType::Individual,
            'cpf' => self::VALID_CPF,
        ], $attributes));
    }

    /**
     * Fecha a conta dos pedidos pagando o total exato na forma informada (ou nos pagamentos dados).
     *
     * @param  list<Order>  $orders
     * @param  list<array<string, mixed>>|null  $payments
     */
    protected function finalizeNfceSale(array $orders, PaymentOption $option, ?array $payments = null, array $standalone = []): Sale
    {
        $total = collect($orders)->sum(fn (Order $order): float => (float) $order->grand_total)
            + collect($standalone)->sum(fn (array $line): float => (float) Product::query()->find($line['product_id'])->price * $line['quantity']);

        return app(FinalizeSale::class)(
            array_map(fn (Order $order): int => $order->id, $orders),
            $standalone,
            $payments ?? [['payment_option_id' => $option->id, 'amount' => $total]],
        );
    }

    protected function nfceSettings(Tenant $tenant): TenantFiscalSettings
    {
        return TenantFiscalSettings::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
    }
}
