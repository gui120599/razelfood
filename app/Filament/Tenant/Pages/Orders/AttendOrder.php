<?php

namespace App\Filament\Tenant\Pages\Orders;

use App\Actions\Menu\ResolvePriceForCartLine;
use App\Actions\Orders\CreateOrderFromCart;
use App\Actions\Orders\ResolveDeliveryFee;
use App\Actions\Orders\UpdateOrderFromCart;
use App\Enums\OrderOrigin;
use App\Exceptions\CheckoutException;
use App\Filament\Tenant\Resources\Orders\OrderResource;
use App\Models\Addon;
use App\Models\DeliveryOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use App\Support\Orders\GiftLineLabel;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use UnitEnum;

/**
 * Workspace de atendimento: colaborador cria pedido por telefone ou edita
 * um pedido existente a pedido do cliente. Carrinho fica em memória (não
 * persiste incrementalmente como o PDV do Pizzaria-App) até a ação final
 * "save", que reaproveita CreateOrderFromCart/UpdateOrderFromCart — o
 * mesmo núcleo transacional já usado/testado pelo Checkout público.
 */
class AttendOrder extends Page
{
    use HasPageShield {
        canAccess as pageShieldCanAccess;
        shouldRegisterNavigation as pageShieldShouldRegisterNavigation;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Pedidos';

    protected static ?string $navigationLabel = 'Criar Pedido';

    protected static ?string $slug = 'pedidos/atender/{order?}';

    protected string $view = 'filament.tenant.pages.orders.attend-order';

    public ?Order $currentOrder = null;

    /** @var array<int, array{type: string, product_id: int, flavor_ids: array<int>, quantity: int, note: ?string, addons?: array<int, array{addon_id:int, quantity:int, target:?int}>}> */
    public array $cartItems = [];

    /** @var array{phone: string, name: string, zip_code: ?string, street: ?string, number: ?string, complement: ?string, neighborhood: ?string, city: ?string, state: ?string, without_client: bool} */
    public array $clientData = [
        'phone' => '', 'name' => '', 'zip_code' => null, 'street' => null, 'number' => null,
        'complement' => null, 'neighborhood' => null, 'city' => null, 'state' => null,
        'without_client' => false,
    ];

    /** @var array{delivery_option_id: ?int, payments: array<int, array{payment_option_id: ?int, amount: ?string, change_for: ?string}>} */
    public array $fulfillmentData = ['delivery_option_id' => null, 'payments' => []];

    public ?string $orderNotes = null;

    public static function canAccess(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::CENTRAL_DE_PEDIDOS) ?? false) && static::pageShieldCanAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::CENTRAL_DE_PEDIDOS) ?? false) && static::pageShieldShouldRegisterNavigation();
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getTitle(): string
    {
        return $this->getHeading();
    }

    public function getHeading(): string
    {
        return $this->currentOrder ? "Editando Pedido #{$this->currentOrder->id}" : 'Novo Pedido por Telefone';
    }

    /**
     * Em requisição HTTP real, $order chega já resolvido via
     * route-model-binding implícito do Laravel (parâmetro `{order?}` do
     * slug bate com o nome do model App\Models\Order — respeita o global
     * scope de tenant do TenantScopedModel normalmente). `int|string` cobre
     * a chamada direta via Livewire::test(..., ['order' => $id]) em testes,
     * que não passa pelo pipeline de binding implícito do router.
     */
    public function mount(Order|int|string|null $order = null): void
    {
        if ($order === null) {
            return;
        }

        $order = $order instanceof Order ? $order : Order::findOrFail($order);
        $this->currentOrder = $order->loadMissing(['items', 'client', 'payments']);

        abort_unless($this->currentOrder->status->isEditableContentWise(), 403, 'Pedido cancelado não pode ser editado.');

        abort_if(
            $this->currentOrder->status->requiresAdvancedPermissionToEdit() && ! auth()->user()->can('edit_order_advanced_status'),
            403,
            'Você não tem permissão para editar um pedido que já passou de Em Preparação.',
        );

        $this->hydrateFromOrder($this->currentOrder);
    }

    private function hydrateFromOrder(Order $order): void
    {
        $this->cartItems = $order->items->map(fn (OrderItem $item) => [
            'type' => $item->flavors ? 'combo' : 'simple',
            'product_id' => $item->product_id,
            'flavor_ids' => $item->flavors ?? [],
            'quantity' => $item->quantity,
            'note' => $item->note,
            'addons' => $item->addons ?? [],
            'gifts' => collect($item->gifts ?? [])
                ->map(fn (array $gift) => ['gift_product_id' => $gift['gift_product_id'], 'accepted' => ($gift['accepted'] ?? false) === true])
                ->all(),
        ])->values()->all();

        $client = $order->client;

        $this->clientData = [
            'phone' => $client?->phone ?? '',
            'name' => $client?->name ?? '',
            'zip_code' => $order->delivery_zip_code,
            'street' => $order->delivery_street,
            'number' => $order->delivery_number,
            'complement' => $order->delivery_complement,
            'neighborhood' => $order->delivery_neighborhood,
            'city' => $order->delivery_city,
            'state' => $order->delivery_state,
            'without_client' => $client === null,
        ];

        // payment_option_name é snapshot em texto (não FK) — melhor esforço pra
        // pré-selecionar a mesma opção pelo nome; se ela foi renomeada/removida
        // desde a criação do pedido, a linha nasce sem opção marcada, mas o
        // valor/troco continuam preenchidos.
        $this->fulfillmentData = [
            'delivery_option_id' => $order->delivery_option_id,
            'payments' => $order->payments->map(fn ($payment) => [
                'payment_option_id' => PaymentOption::where('name', $payment->payment_option_name)->value('id'),
                'amount' => number_format((float) $payment->amount, 2, ',', '.'),
                'change_for' => $payment->change_for !== null ? number_format((float) $payment->change_for, 2, ',', '.') : null,
            ])->values()->all(),
        ];

        $this->orderNotes = $order->notes;
    }

    #[On('order-item-selected')]
    public function addSimpleItem(int $productId): void
    {
        $this->cartItems[] = ['type' => 'simple', 'product_id' => $productId, 'flavor_ids' => [], 'quantity' => 1, 'note' => null, 'addons' => [], 'gifts' => []];
        unset($this->cartLines);
    }

    #[On('order-cart-line-confirmed')]
    public function addConfirmedLine(array $item): void
    {
        $this->cartItems[] = $item;
        unset($this->cartLines);
    }

    /**
     * Reabre o modal de adicionais (AddonPickerModal) para uma linha já no
     * carrinho — caso o atendente tenha pulado a etapa ou queira revisar.
     */
    public function editLineAddons(int $index): void
    {
        $item = $this->cartItems[$index] ?? null;

        if ($item === null) {
            return;
        }

        $this->dispatch(
            'order-line-addons-edit-requested',
            index: $index,
            type: $item['type'],
            productId: $item['product_id'],
            flavorIds: $item['flavor_ids'],
            addons: $item['addons'] ?? [],
        );
    }

    /**
     * @param  array<int, array{addon_id:int, quantity:int, target:?int}>  $addons
     */
    #[On('order-line-addons-updated')]
    public function updateLineAddons(int $index, array $addons): void
    {
        if (! isset($this->cartItems[$index])) {
            return;
        }

        $this->cartItems[$index]['addons'] = $addons;
        unset($this->cartLines);
    }

    #[On('order-client-data-changed')]
    public function syncClientData(array $data): void
    {
        $this->clientData = $data;
    }

    #[On('order-fulfillment-changed')]
    public function syncFulfillmentData(array $data): void
    {
        $this->fulfillmentData = $data;
    }

    public function removeItem(int $index): void
    {
        unset($this->cartItems[$index]);
        $this->cartItems = array_values($this->cartItems);
        unset($this->cartLines);
    }

    public function updateItemQuantity(int $index, int $quantity): void
    {
        if ($quantity < 1 || ! isset($this->cartItems[$index])) {
            return;
        }

        $this->cartItems[$index]['quantity'] = $quantity;
        unset($this->cartLines);
    }

    public function updateItemNote(int $index, string $note): void
    {
        if (! isset($this->cartItems[$index])) {
            return;
        }

        $this->cartItems[$index]['note'] = $note !== '' ? $note : null;
        unset($this->cartLines);
    }

    #[Computed]
    public function cartLines(): array
    {
        $resolve = app(ResolvePriceForCartLine::class);
        $lines = [];

        $productIds = collect($this->cartItems)
            ->flatMap(fn (array $item) => [$item['product_id'], ...$item['flavor_ids']])
            ->filter()
            ->unique()
            ->values();

        $products = $productIds->isEmpty()
            ? collect()
            : Product::with('category:id,name')->whereIn('id', $productIds)->get()->keyBy('id');

        $productIdsWithAddons = $productIds->isEmpty()
            ? collect()
            : ProductAddon::whereIn('product_id', $productIds)->pluck('product_id')->unique();

        $addonIds = collect($this->cartItems)->flatMap(fn (array $item) => $item['addons'] ?? [])->pluck('addon_id')->unique()->values();
        $addonNames = $addonIds->isEmpty() ? collect() : Addon::whereIn('id', $addonIds)->pluck('name', 'id');

        $giftIds = collect($this->cartItems)->flatMap(fn (array $item) => $item['gifts'] ?? [])->pluck('gift_product_id')->unique()->values();
        $giftNames = $giftIds->isEmpty() ? collect() : Product::whereIn('id', $giftIds)->pluck('name', 'id');

        foreach ($this->cartItems as $index => $item) {
            try {
                $resolved = $resolve($item);
            } catch (InvalidArgumentException) {
                continue;
            }

            $relevantIds = $item['type'] === 'combo' ? $item['flavor_ids'] : [$item['product_id']];
            $mainProductId = $item['type'] === 'combo' ? ($item['flavor_ids'][0] ?? null) : $item['product_id'];
            $mainProduct = $mainProductId ? $products->get($mainProductId) : null;

            $name = $item['type'] === 'combo'
                ? collect($item['flavor_ids'])->map(fn (int $id) => $products->get($id)?->name)->filter()->implode(' / ')
                : ($mainProduct?->name ?? 'Produto removido');

            $addonsDisplay = collect($item['addons'] ?? [])->map(function (array $selection) use ($addonNames, $products) {
                $addonName = $addonNames->get($selection['addon_id'], 'Adicional removido');
                $target = $selection['target'] !== null ? ($products->get($selection['target'])?->name ?? 'sabor removido') : 'produto inteiro';

                return "{$selection['quantity']}x {$addonName} ({$target})";
            })->all();

            $giftsDisplay = collect($resolved['gifts'] ?? [])
                ->filter(fn (array $gift) => $gift['accepted'] === true)
                ->map(fn (array $gift) => GiftLineLabel::accepted($gift, $item['quantity'], $giftNames->get($gift['gift_product_id']) ?? 'Brinde removido'))
                ->values()
                ->all();

            $lines[] = [
                'index' => $index,
                'name' => $name,
                'category_name' => $mainProduct?->category?->name,
                'image_url' => $mainProduct?->image_url,
                'has_addons' => collect($relevantIds)->intersect($productIdsWithAddons)->isNotEmpty(),
                'quantity' => $item['quantity'],
                'note' => $item['note'],
                'unit_price' => $resolved['unit_price'],
                'addons_total' => $resolved['addons_total'],
                'addons_display' => $addonsDisplay,
                'gifts_display' => $giftsDisplay,
                'line_total' => round(($resolved['unit_price'] + $resolved['addons_total']) * $item['quantity'], 2),
                'is_combo' => $item['type'] === 'combo',
            ];
        }

        return $lines;
    }

    #[Computed]
    public function cartTotal(): float
    {
        return round(array_sum(array_column($this->cartLines, 'line_total')), 2);
    }

    /**
     * Preview de taxa de entrega, só pra exibição — o cálculo real acontece
     * de novo dentro de CreateOrderFromCart/UpdateOrderFromCart no save()
     * (RN-13, nunca confia em cálculo do front).
     *
     * @return array{fee: float, delivery_zone_id: ?int, is_unlisted_neighborhood: bool, base_fee: float, unlisted_surcharge: float, blocked: bool, message: ?string}|null
     */
    #[Computed]
    public function deliveryFeePreview(): ?array
    {
        $option = $this->selectedDeliveryOption();

        if (! $option || ! $option->requires_address || blank($this->clientData['neighborhood'] ?? null)) {
            return null;
        }

        try {
            $resolved = app(ResolveDeliveryFee::class)($option, $this->clientData['neighborhood'], $this->clientData['city'] ?? null, $this->cartTotal);

            return [...$resolved, 'blocked' => false, 'message' => null];
        } catch (CheckoutException $e) {
            return ['fee' => 0.0, 'delivery_zone_id' => null, 'is_unlisted_neighborhood' => false, 'base_fee' => 0.0, 'unlisted_surcharge' => 0.0, 'blocked' => true, 'message' => $e->getMessage()];
        }
    }

    #[Computed]
    public function deliveryFeePreviewAmount(): float
    {
        $option = $this->selectedDeliveryOption();

        if (! $option) {
            return 0.0;
        }

        if (! $option->requires_address) {
            if ($option->min_order_for_free_delivery !== null && $this->cartTotal >= $option->min_order_for_free_delivery) {
                return 0.0;
            }

            return (float) $option->delivery_fee;
        }

        return $this->deliveryFeePreview['fee'] ?? 0.0;
    }

    #[Computed]
    public function grandTotalPreview(): float
    {
        return round($this->cartTotal + $this->deliveryFeePreviewAmount, 2);
    }

    private function selectedDeliveryOption(): ?DeliveryOption
    {
        $id = $this->fulfillmentData['delivery_option_id'] ?? null;

        return $id ? DeliveryOption::find($id) : null;
    }

    private function notifyError(string $message): void
    {
        Notification::make()
            ->title($message)
            ->danger()
            ->send();
    }

    public function save(): void
    {
        if (empty($this->cartItems)) {
            $this->notifyError('Adicione ao menos um item ao pedido.');

            return;
        }

        if (! ($this->clientData['without_client'] ?? false)
            && (blank($this->clientData['phone'] ?? null) || blank($this->clientData['name'] ?? null))) {
            $this->notifyError('Informe telefone e nome do cliente, ou marque "Pedido sem cliente".');

            return;
        }

        $payments = $this->fulfillmentData['payments'] ?? [];

        // Conveniência: com só 1 forma de pagamento, o valor é implicitamente
        // o total do pedido — não obriga o atendente a digitar o óbvio.
        if (count($payments) === 1 && blank($payments[0]['amount'] ?? null)) {
            $payments[0]['amount'] = number_format($this->grandTotalPreview, 2, ',', '.');
            $this->fulfillmentData['payments'] = $payments;
        }

        if (empty($payments) || collect($payments)->contains(fn (array $p) => blank($p['payment_option_id'] ?? null) || blank($p['amount'] ?? null))) {
            $this->notifyError('Escolha ao menos uma forma de pagamento e informe o valor de cada uma.');

            return;
        }

        $paymentsSum = collect($payments)->sum(fn (array $p) => $this->parseBrl($p['amount']));

        if (abs($paymentsSum - $this->grandTotalPreview) > 0.01) {
            $this->notifyError('A soma das formas de pagamento (R$ '.number_format($paymentsSum, 2, ',', '.').') precisa bater com o total do pedido (R$ '.number_format($this->grandTotalPreview, 2, ',', '.').').');

            return;
        }

        $deliveryOption = $this->selectedDeliveryOption();

        if ($deliveryOption?->requires_address) {
            if (blank($this->clientData['street'] ?? null) || blank($this->clientData['neighborhood'] ?? null)) {
                $this->notifyError('Informe rua e bairro para entrega.');

                return;
            }

            if (blank($this->clientData['number'] ?? null) && blank($this->clientData['complement'] ?? null)) {
                $this->notifyError('Sem número? Informe um complemento ou ponto de referência.');

                return;
            }
        }

        $checkoutData = $this->buildCheckoutDataArray();

        try {
            $order = $this->currentOrder
                ? app(UpdateOrderFromCart::class)($this->currentOrder, $this->cartItems, $checkoutData)
                : app(CreateOrderFromCart::class)($this->cartItems, $checkoutData, origin: OrderOrigin::Staff, bypassBusinessHours: true);
        } catch (CheckoutException|InvalidArgumentException $e) {
            $this->notifyError($e->getMessage());

            return;
        }

        Notification::make()
            ->title($this->currentOrder ? "Pedido #{$order->id} atualizado" : "Pedido #{$order->id} criado")
            ->success()
            ->send();

        $this->redirect(OrderResource::getUrl('view', ['record' => $order]));
    }

    /**
     * @return array{phone: string, name: string, zip_code: ?string, street: ?string, number: ?string, complement: ?string, neighborhood: ?string, city: ?string, state: ?string, delivery_option_id: ?int, payments: array<int, array{payment_option_id: int, amount: float, change_for: ?float}>, notes: ?string}
     */
    private function buildCheckoutDataArray(): array
    {
        $withoutClient = $this->clientData['without_client'] ?? false;

        return [
            'phone' => $withoutClient ? '' : $this->clientData['phone'],
            'name' => $withoutClient ? '' : $this->clientData['name'],
            'zip_code' => $this->clientData['zip_code'],
            'street' => $this->clientData['street'],
            'number' => $this->clientData['number'],
            'complement' => $this->clientData['complement'],
            'neighborhood' => $this->clientData['neighborhood'],
            'city' => $this->clientData['city'],
            'state' => $this->clientData['state'],
            'delivery_option_id' => $this->fulfillmentData['delivery_option_id'],
            'payments' => collect($this->fulfillmentData['payments'] ?? [])->map(fn (array $p) => [
                'payment_option_id' => (int) $p['payment_option_id'],
                'amount' => $this->parseBrl($p['amount']),
                'change_for' => blank($p['change_for'] ?? null) ? null : $this->parseBrl($p['change_for']),
            ])->all(),
            'notes' => blank($this->orderNotes) ? null : $this->orderNotes,
        ];
    }

    /**
     * Converte "1.234,56" (máscara BRL digitada) pra float.
     */
    private function parseBrl(?string $value): float
    {
        if (blank($value)) {
            return 0.0;
        }

        return (float) str_replace(['.', ','], ['', '.'], $value);
    }
}
