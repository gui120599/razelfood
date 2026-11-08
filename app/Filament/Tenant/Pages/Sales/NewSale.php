<?php

namespace App\Filament\Tenant\Pages\Sales;

use App\Actions\Fiscal\IssueFiscalDocument;
use App\Actions\Fiscal\ShouldAutoIssue;
use App\Actions\Fiscal\SyncFiscalDocumentStatus;
use App\Actions\Sales\AddSalePayment;
use App\Actions\Sales\AddStandaloneItem;
use App\Actions\Sales\ApplySaleDiscountPercent;
use App\Actions\Sales\AttachOrderItems;
use App\Actions\Sales\CancelSale;
use App\Actions\Sales\DetachOrderItems;
use App\Actions\Sales\FinalizeOpenSale;
use App\Actions\Sales\RemoveSalePayment;
use App\Actions\Sales\RemoveStandaloneItem;
use App\Actions\Sales\SetLineDiscount;
use App\Actions\Sales\SetSaleClient;
use App\Actions\Sales\SetSaleDeliveryFee;
use App\Actions\Sales\SplitSaleBill;
use App\Actions\Sales\StartSale;
use App\Actions\Sales\UpdateSalePayment;
use App\Actions\Sales\UpdateStandaloneItem;
use App\Enums\CancellationReason;
use App\Enums\FiscalDocumentDecision;
use App\Enums\FiscalDocumentStatus;
use App\Enums\PersonType;
use App\Enums\SaleStatus;
use App\Exceptions\FiscalDocumentException;
use App\Exceptions\NfeIoException;
use App\Exceptions\SaleException;
use App\Models\CardBrand;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Scopes\TenantScope;
use App\Models\TenantFiscalSettings;
use App\Rules\ValidCnpj;
use App\Rules\ValidCpf;
use App\Services\Fiscal\NfeIoClient;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use App\Support\Sales\Money;
use App\Support\Sales\PaymentSummary;
use App\Support\Sales\SaleCartLines;
use BackedEnum;
use Closure;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use UnitEnum;

/**
 * Nova Venda (Fase 1.R.2): a tela "Venda" do Pizzaria-App copiada. O rascunho (venda `open`) é
 * criado no primeiro lançamento e cada ação grava direto pelas Actions de venda (nada de estado
 * só na tela): item avulso, itens de pedido (reserva item a item), cliente, desconto %, frete,
 * pagamentos, dividir conta, finalizar e cancelar. A regra mora nas Actions; esta classe só liga
 * a tela a elas. Rascunho vazio é descartado ao sair (sendBeacon) e por comando agendado.
 */
class NewSale extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Vendas';

    protected static ?string $navigationLabel = 'Nova Venda';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Venda';

    protected static ?string $slug = 'vendas/operar/{sale?}';

    protected string $view = 'filament.tenant.pages.sales.new-sale';

    private const TAB_SESSION_KEY = 'new_sale_default_tab';

    private const CARDS_SESSION_KEY = 'new_sale_open_cards';

    private const ORDERS_LIMIT = 50;

    /** Consultas da NFC-e (3 s cada): passado disso o modal para de girar e mostra o que fazer. */
    private const FISCAL_MAX_POLLS = 40;

    #[Locked]
    public ?int $saleId = null;

    public string $activeTab = 'orders';

    public bool $openCardsByDefault = false;

    public string $orderSearch = '';

    public ?string $stockError = null;

    // Cliente
    public bool $clientModalOpen = false;

    public string $clientSearch = '';

    public string $adHocName = '';

    public string $adHocCpf = '';

    public string $adHocCnpj = '';

    public string $adHocPhone = '';

    public string $adHocEmail = '';

    // Pagamento
    public bool $paymentModalOpen = false;

    public ?int $paymentOptionId = null;

    public ?int $cardBrandId = null;

    public ?string $cardBrandText = null;

    public ?string $authorizationCode = null;

    public float $receivedAmount = 0.0;

    public float $tenderedAmount = 0.0;

    public ?int $editingPaymentId = null;

    // Dividir conta
    public bool $splitModalOpen = false;

    public int $splitPeople = 2;

    public ?int $splitOptionId = null;

    // Cancelar
    public bool $cancelModalOpen = false;

    public string $cancellationNote = '';

    // NFC-e
    public bool $fiscalModalOpen = false;

    public ?string $fiscalStatus = null;

    public ?string $fiscalError = null;

    public ?string $fiscalNotice = null;

    #[Locked]
    public ?int $finalizedSaleId = null;

    #[Locked]
    public ?int $fiscalDocumentId = null;

    public int $fiscalPolls = 0;

    /** O envio pode ter chegado à NFE.io sem sabermos: reenviar pode duplicar a nota. */
    public bool $fiscalAmbiguous = false;

    public static function canAccess(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::PDV) ?? false)
            && (auth()->user()?->can('manage_sales') ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(Sale|int|string|null $sale = null): void
    {
        $this->activeTab = session(self::TAB_SESSION_KEY, 'orders') === 'products' ? 'products' : 'orders';
        $this->openCardsByDefault = (bool) session(self::CARDS_SESSION_KEY, false);

        if ($sale !== null) {
            $id = $sale instanceof Sale ? $sale->id : (int) $sale;
            $open = Sale::query()->whereKey($id)->where('status', SaleStatus::Open)->first();

            if ($open === null) {
                Notification::make()->title('Essa venda não está mais aberta.')->warning()->send();
                $this->redirect(static::getUrl());

                return;
            }

            $this->saleId = $open->id;

            return;
        }

        $this->launchRequestedOrders();
    }

    /**
     * URLs antigas ("Contas a fechar" → `?orders=1,2`) lançam os pedidos pedidos na venda nova.
     */
    private function launchRequestedOrders(): void
    {
        $requested = collect(explode(',', (string) request()->query('orders', '')))
            ->map(fn (string $id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->values();

        if ($requested->isEmpty()) {
            return;
        }

        $available = Order::query()->awaitingSale()->whereIn('orders.id', $requested)->orderBy('orders.id')->pluck('orders.id');

        if ($available->count() !== $requested->count()) {
            Notification::make()
                ->title('Alguns pedidos não estão mais disponíveis para fechar (já cobrados ou cancelados).')
                ->warning()
                ->send();
        }

        if ($available->isEmpty()) {
            return;
        }

        $launched = $this->guard(function () use ($available): void {
            $sale = $this->ensureSale();

            foreach ($available as $orderId) {
                app(AttachOrderItems::class)($sale, (int) $orderId);
            }
        });

        // A URL passa a carregar o rascunho: um F5 retoma a venda em vez de relançar `?orders=` (que
        // apareceria como "indisponível" e deixaria o rascunho antigo preso, com os itens reservados).
        if ($launched && $this->saleId !== null) {
            $this->redirect(static::getUrl(['sale' => $this->saleId]));
        }
    }

    public function getTitle(): string
    {
        return 'Venda';
    }

    // ── Estado derivado ────────────────────────────────────────────────

    #[Computed]
    public function sale(): ?Sale
    {
        return $this->saleId === null
            ? null
            : Sale::query()->with('client')->whereKey($this->saleId)->where('status', SaleStatus::Open)->first();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function cartLines(): Collection
    {
        return $this->sale === null ? collect() : SaleCartLines::for($this->sale);
    }

    /**
     * @return Collection<int, SalePayment>
     */
    #[Computed]
    public function payments(): Collection
    {
        return $this->sale === null ? collect() : SalePayment::query()->where('sale_id', $this->sale->id)->orderBy('id')->get();
    }

    private function paymentSummary(): PaymentSummary
    {
        return PaymentSummary::fromLines(
            Money::toCents($this->sale?->total),
            $this->payments->map(fn (SalePayment $payment): array => ['amount_cents' => Money::toCents($payment->amount), 'is_cash' => $payment->is_cash]),
        );
    }

    #[Computed]
    public function remainingCents(): int
    {
        return $this->paymentSummary()->remainingCents();
    }

    #[Computed]
    public function changeCents(): int
    {
        $summary = $this->paymentSummary();

        return $summary->canGiveChange() ? $summary->changeCents() : 0;
    }

    #[Computed]
    public function paidCents(): int
    {
        return $this->paymentSummary()->receivedCents - $this->changeCents;
    }

    /**
     * Pedidos com item livre (ou já lançado nesta venda), para a aba Pedidos.
     *
     * @return Collection<int, Order>
     */
    #[Computed]
    public function availableOrders(): Collection
    {
        return $this->availableOrdersQuery()->limit(self::ORDERS_LIMIT)->get();
    }

    /**
     * Há mais pedidos pendentes do que a lista mostra? (a busca encontra os demais)
     */
    #[Computed]
    public function ordersTruncated(): bool
    {
        return $this->availableOrdersQuery()->count() > self::ORDERS_LIMIT;
    }

    private function availableOrdersQuery(): Builder
    {
        $term = trim($this->orderSearch);

        return Order::query()
            ->with(['client', 'items'])
            ->where(function ($query): void {
                $query->awaitingSale();

                if ($this->saleId !== null) {
                    $query->orWhereIn('orders.id', OrderItem::query()->where('sale_id', $this->saleId)->select('order_id'));
                }
            })
            ->when($term !== '', fn ($query) => $query->where(function ($search) use ($term): void {
                $search->where('orders.id', (int) ltrim($term, '#'))
                    ->orWhereHas('client', fn ($client) => $client->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'));
            }))
            ->orderByDesc('orders.id');
    }

    /**
     * Vendas dos itens que já estão em alguma venda (número/situação para os selos).
     *
     * @return Collection<int, Sale>
     */
    #[Computed]
    public function itemSales(): Collection
    {
        $ids = $this->availableOrders->flatMap(fn (Order $order) => $order->items->pluck('sale_id'))->filter()->unique()->values();

        return $ids->isEmpty() ? collect() : Sale::query()->whereIn('id', $ids)->get(['id', 'number', 'status'])->keyBy('id');
    }

    /**
     * @return array{state: 'free'|'this'|'other'|'paid', label: ?string}
     */
    public function itemState(OrderItem $item): array
    {
        if ($item->sale_id === null) {
            return ['state' => 'free', 'label' => null];
        }

        if ($item->sale_id === $this->saleId) {
            return ['state' => 'this', 'label' => null];
        }

        $sale = $this->itemSales->get($item->sale_id);

        return $sale?->status === SaleStatus::Finalized
            ? ['state' => 'paid', 'label' => null]
            : ['state' => 'other', 'label' => $sale?->number !== null ? "venda #{$sale->number}" : "rascunho #{$item->sale_id}"];
    }

    /**
     * Linhas (nome, sabores, adicionais) dos itens de todos os pedidos listados, por id do item.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function orderItemLines(): Collection
    {
        return SaleCartLines::forOrderItems($this->availableOrders->flatMap(fn (Order $order) => $order->items)->values())->keyBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function orderItemLine(OrderItem $item): array
    {
        return $this->orderItemLines->get($item->id);
    }

    public function orderIsLaunched(Order $order): bool
    {
        return $this->saleId !== null
            && $order->items->isNotEmpty()
            && $order->items->every(fn (OrderItem $item): bool => $item->sale_id === $this->saleId);
    }

    /**
     * Pagamento que os pedidos lançados declararam (somado por forma): sugestão no modal, que
     * pré-seleciona a forma quando o nome ainda existe entre as formas do estabelecimento.
     *
     * @return array{description: string, option_id: ?int, cents: int}|null
     */
    #[Computed]
    public function suggestedPayment(): ?array
    {
        if ($this->sale === null) {
            return null;
        }

        $orderIds = OrderItem::query()->where('sale_id', $this->sale->id)->distinct()->pluck('order_id');

        if ($orderIds->isEmpty()) {
            return null;
        }

        $declared = [];

        foreach (Order::query()->with('payments')->whereIn('id', $orderIds)->get() as $order) {
            foreach ($order->payments as $payment) {
                $declared[$payment->payment_option_name] = ($declared[$payment->payment_option_name] ?? 0) + Money::toCents($payment->amount);
            }
        }

        $declared = array_filter($declared, fn (int $cents): bool => $cents > 0);

        if ($declared === []) {
            return null;
        }

        $options = $this->paymentOptions->keyBy('name');
        $first = array_key_first($declared);

        return [
            'description' => collect($declared)->map(fn (int $cents, string $name): string => $name.' '.Money::format($cents))->implode(' · '),
            'option_id' => $options->get($first)?->id,
            'cents' => $declared[$first],
        ];
    }

    /**
     * @return Collection<int, PaymentOption>
     */
    #[Computed]
    public function paymentOptions(): Collection
    {
        return PaymentOption::query()->orderBy('name')->get();
    }

    #[Computed]
    public function usesRegisteredCardBrands(): bool
    {
        return CurrentTenant::get()?->hasFeature(FeatureKey::NFE_EMISSAO) ?? false;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function cardBrands(): array
    {
        return CardBrand::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return Collection<int, Client>
     */
    #[Computed]
    public function foundClients(): Collection
    {
        $term = trim($this->clientSearch);

        if ($term === '') {
            return collect();
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $digits = preg_replace('/\D/', '', $term);
        $termForDigits = preg_replace('/\s+/', '', $term);

        // Grupo ÚNICO: os orWhere ficam dentro do where() para o escopo de tenant continuar valendo.
        return Client::query()
            ->where(function ($query) use ($like, $digits, $termForDigits): void {
                $query->where('name', 'like', $like);

                if (strlen($digits) >= 3 && strlen($digits) * 2 >= strlen($termForDigits)) {
                    $query->orWhere('cpf', 'like', "%{$digits}%")
                        ->orWhere('cnpj', 'like', "%{$digits}%")
                        ->orWhere('phone', 'like', "%{$digits}%");
                }
            })
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function cancellationReasons(): array
    {
        return array_map(fn (CancellationReason $reason): string => $reason->label(), CancellationReason::cases());
    }

    public function defaultTab(): string
    {
        return session(self::TAB_SESSION_KEY, 'orders') === 'products' ? 'products' : 'orders';
    }

    public function setDefaultTab(): void
    {
        session([self::TAB_SESSION_KEY => $this->activeTab]);
    }

    public function toggleOpenCardsByDefault(): void
    {
        $this->openCardsByDefault = ! $this->openCardsByDefault;
        session([self::CARDS_SESSION_KEY => $this->openCardsByDefault]);
    }

    public function closeStockToast(): void
    {
        $this->stockError = null;
    }

    // ── Rascunho: criação e proteção ───────────────────────────────────

    private function authorizeOperation(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    private function ensureSale(): Sale
    {
        $sale = $this->sale;

        if ($sale !== null) {
            return $sale;
        }

        $sale = app(StartSale::class)(auth()->user());
        $this->saleId = $sale->id;
        $this->refreshState();

        return $sale;
    }

    /**
     * Executa uma operação de venda: SaleException vira aviso na tela (nada é perdido) e, se a
     * venda deixou de estar aberta, a tela volta ao começo.
     */
    private function guard(Closure $operation): bool
    {
        $this->authorizeOperation();

        try {
            $operation();
        } catch (SaleException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
            $this->refreshState();

            if ($this->saleId !== null && $this->sale === null) {
                $this->redirect(static::getUrl());
            }

            return false;
        }

        $this->resetErrorBag('finalize');
        $this->refreshState();

        return true;
    }

    private function refreshState(): void
    {
        unset(
            $this->sale, $this->cartLines, $this->payments, $this->remainingCents, $this->changeCents,
            $this->paidCents, $this->suggestedPayment, $this->availableOrders, $this->ordersTruncated, $this->itemSales, $this->orderItemLines, $this->foundClients,
        );
    }

    // ── Produtos e carrinho ────────────────────────────────────────────

    #[On('product-selected')]
    public function addStandaloneProduct(int $productId): void
    {
        $this->stockError = null;

        try {
            $this->authorizeOperation();
            app(AddStandaloneItem::class)($this->ensureSale(), $productId);
        } catch (SaleException $exception) {
            // Banner da aba Produtos (como na origem) e toast: a mensagem é a da própria regra.
            $this->stockError = $exception->getMessage();
            Notification::make()->title($exception->getMessage())->danger()->send();
        }

        $this->refreshState();
    }

    public function changeQuantity(int $saleItemId, int $quantity): void
    {
        $this->guard(function () use ($saleItemId, $quantity): void {
            $sale = $this->ensureSale();

            $quantity < 1
                ? app(RemoveStandaloneItem::class)($sale, $saleItemId)
                : app(UpdateStandaloneItem::class)($sale, $saleItemId, $quantity);
        });
    }

    /**
     * Botões − 1 + de qualquer linha. Como na origem, só o item avulso muda de quantidade: a linha vinda
     * de pedido tem a quantidade do pedido (cobrar menos unidades = tirar o item na aba Pedidos).
     */
    public function changeLineQuantity(string $kind, int $id, int $quantity): void
    {
        $this->authorizeOperation();

        if ($kind === 'order') {
            Notification::make()
                ->warning()
                ->title('Item lançado de pedido')
                ->body('A quantidade é a do pedido. Para cobrar menos unidades, desmarque o item na aba Pedidos.')
                ->send();

            return;
        }

        $this->changeQuantity($id, $quantity);
    }

    /**
     * Campo "Desc. R$" da linha: desconto em reais, limitado ao valor da linha.
     */
    public function updateLineDiscount(string $kind, int $id, float|int|string $amount): void
    {
        // Sem ensureSale(): numa tela velha (rascunho já descartado) não se cria rascunho só para recusar.
        $this->guard(fn () => app(SetLineDiscount::class)($this->sale ?? throw new SaleException('Esta venda não está mais aberta.'), $kind, $id, $amount));
    }

    public function removeCartLine(string $kind, int $id): void
    {
        $this->guard(function () use ($kind, $id): void {
            $sale = $this->ensureSale();

            if ($kind === 'standalone') {
                app(RemoveStandaloneItem::class)($sale, $id);

                return;
            }

            $item = OrderItem::query()->whereKey($id)->where('sale_id', $sale->id)->first()
                ?? throw new SaleException('Item não encontrado nesta venda.');

            app(DetachOrderItems::class)($sale, (int) $item->order_id, [$item->id]);
        });
    }

    // ── Pedidos (item a item) ──────────────────────────────────────────

    public function launchOrder(int $orderId): void
    {
        $this->guard(fn () => app(AttachOrderItems::class)($this->ensureSale(), $orderId));
    }

    public function removeOrder(int $orderId): void
    {
        $this->guard(fn () => app(DetachOrderItems::class)($this->ensureSale(), $orderId));
    }

    public function toggleOrderItem(int $itemId): void
    {
        $this->guard(function () use ($itemId): void {
            $item = OrderItem::query()->find($itemId);
            // OrderItem não tem tenant_id: só vale se o pedido dele é deste estabelecimento.
            $order = $item === null ? null : Order::query()->find($item->order_id);

            if ($item === null || $order === null) {
                throw new SaleException('Item não encontrado.');
            }

            $sale = $this->ensureSale();

            if ($item->sale_id === $sale->id) {
                app(DetachOrderItems::class)($sale, $order->id, [$item->id]);

                return;
            }

            if ($item->sale_id !== null) {
                throw new SaleException('Este item já está em outra venda.');
            }

            app(AttachOrderItems::class)($sale, $order->id, [$item->id]);
        });
    }

    // ── Desconto e frete ───────────────────────────────────────────────

    public function applyDiscountPercent(mixed $percent): void
    {
        $this->guard(fn () => app(ApplySaleDiscountPercent::class)($this->ensureSale(), $percent === '' ? null : $percent));
    }

    public function clearDiscountPercent(): void
    {
        $this->guard(fn () => app(ApplySaleDiscountPercent::class)($this->ensureSale(), null));
    }

    public function updateDeliveryFee(float|int|string $fee): void
    {
        $this->guard(fn () => app(SetSaleDeliveryFee::class)($this->ensureSale(), $fee));
    }

    // ── Cliente ────────────────────────────────────────────────────────

    public function openClientModal(): void
    {
        $this->clientSearch = '';
        $this->reset(['adHocName', 'adHocCpf', 'adHocCnpj', 'adHocPhone', 'adHocEmail']);
        $this->resetErrorBag();
        $this->clientModalOpen = true;
    }

    public function closeClientModal(): void
    {
        $this->clientModalOpen = false;
    }

    public function selectClient(int $clientId): void
    {
        if ($this->guard(fn () => app(SetSaleClient::class)($this->ensureSale(), $clientId))) {
            $this->clientModalOpen = false;
        }
    }

    public function removeClient(): void
    {
        $this->guard(fn () => app(SetSaleClient::class)($this->ensureSale(), null));
    }

    public function saveAdHocClient(): void
    {
        $this->authorizeOperation();

        $data = Validator::make([
            'adHocName' => trim($this->adHocName),
            'adHocCpf' => preg_replace('/\D/', '', $this->adHocCpf),
            'adHocCnpj' => preg_replace('/\D/', '', $this->adHocCnpj),
            'adHocPhone' => preg_replace('/\D/', '', $this->adHocPhone),
            'adHocEmail' => trim($this->adHocEmail),
        ], [
            'adHocName' => ['required', 'string', 'max:255'],
            'adHocCpf' => ['nullable', new ValidCpf],
            'adHocCnpj' => ['nullable', new ValidCnpj],
            'adHocPhone' => ['required', 'digits_between:10,13'],
            'adHocEmail' => ['nullable', 'email', 'max:255'],
        ], [
            'adHocName.required' => 'Informe o nome do cliente.',
            'adHocPhone.required' => 'Informe o telefone do cliente.',
            'adHocPhone.digits_between' => 'Telefone inválido.',
            'adHocEmail.email' => 'E-mail inválido.',
        ])->validate();

        $client = Client::query()->where('phone', $data['adHocPhone'])->first()
            ?? Client::create([
                'tenant_id' => CurrentTenant::id(),
                'name' => $data['adHocName'],
                'phone' => $data['adHocPhone'],
                'cpf' => $data['adHocCpf'] ?: null,
                'cnpj' => $data['adHocCnpj'] ?: null,
                'person_type' => $data['adHocCnpj'] !== '' ? PersonType::Company : PersonType::Individual,
                'email' => $data['adHocEmail'] ?: null,
            ]);

        $this->selectClient($client->id);
    }

    // ── Pagamentos ─────────────────────────────────────────────────────

    public function openPaymentModal(): void
    {
        $this->authorizeOperation();
        $this->resetPaymentForm();
        $this->paymentOptionId = $this->suggestedPayment['option_id']
            ?? $this->paymentOptions->firstWhere('is_cash', true)?->id
            ?? $this->paymentOptions->first()?->id;
        $this->receivedAmount = $this->remainingCents / 100;
        $this->paymentModalOpen = true;
    }

    public function closePaymentModal(): void
    {
        $this->paymentModalOpen = false;
        $this->resetPaymentForm();
    }

    private function resetPaymentForm(): void
    {
        $this->reset(['paymentOptionId', 'cardBrandId', 'cardBrandText', 'authorizationCode', 'receivedAmount', 'tenderedAmount', 'editingPaymentId']);
        $this->resetErrorBag();
    }

    public function updatedPaymentOptionId(): void
    {
        $this->reset(['cardBrandId', 'cardBrandText', 'authorizationCode', 'tenderedAmount']);
    }

    public function registerPayment(): void
    {
        $this->authorizeOperation();

        $option = $this->paymentOptions->firstWhere('id', $this->paymentOptionId);

        if ($option === null) {
            $this->addError('receivedAmount', 'Escolha a forma de pagamento.');

            return;
        }

        if ($this->receivedAmount <= 0) {
            $this->addError('receivedAmount', 'Informe um valor maior que zero.');

            return;
        }

        // Em dinheiro, "pago pelo cliente" é o valor entregue (o troco sai no fechamento, no servidor).
        $tendered = $option->is_cash && $this->tenderedAmount > 0 ? $this->tenderedAmount : $this->receivedAmount;

        if ($tendered + 0.00001 < $this->receivedAmount) {
            $this->addError('receivedAmount', 'O valor pago pelo cliente não pode ser menor que o valor recebido.');

            return;
        }

        $payload = [
            'payment_option_id' => $option->id,
            'amount' => $tendered,
            'card_brand_id' => $this->usesRegisteredCardBrands ? $this->cardBrandId : null,
            'card_brand' => $this->usesRegisteredCardBrands ? null : $this->cardBrandText,
            'authorization_code' => $this->authorizationCode,
        ];

        $saved = $this->guardPayment(function () use ($payload): void {
            $sale = $this->ensureSale();

            $this->editingPaymentId === null
                ? app(AddSalePayment::class)($sale, $payload)
                : app(UpdateSalePayment::class)($sale, $this->editingPaymentId, $payload);
        });

        if ($saved) {
            $this->closePaymentModal();
        }
    }

    /**
     * Como `guard`, mas o erro aparece no próprio modal (campo de valor), não em toast.
     */
    private function guardPayment(Closure $operation): bool
    {
        $this->authorizeOperation();

        try {
            $operation();
        } catch (SaleException $exception) {
            $this->addError('receivedAmount', $exception->getMessage());

            return false;
        }

        $this->refreshState();

        return true;
    }

    public function editPayment(int $paymentId): void
    {
        $this->authorizeOperation();

        $payment = $this->payments->firstWhere('id', $paymentId);

        if ($payment === null) {
            return;
        }

        $this->resetPaymentForm();
        $this->editingPaymentId = $payment->id;
        $this->paymentOptionId = $payment->payment_option_id;
        $this->receivedAmount = (float) $payment->amount;
        $this->cardBrandId = $payment->card_brand_id;
        $this->cardBrandText = $payment->card_brand_id === null ? $payment->card_brand : null;
        $this->authorizationCode = $payment->authorization_code;
        $this->paymentModalOpen = true;
    }

    public function removePayment(int $paymentId): void
    {
        $this->guard(fn () => app(RemoveSalePayment::class)($this->ensureSale(), $paymentId));
    }

    // ── Dividir conta ──────────────────────────────────────────────────

    public function openSplitModal(): void
    {
        $this->authorizeOperation();
        $this->splitPeople = 2;
        $this->splitOptionId = $this->paymentOptions->first()?->id;
        $this->splitModalOpen = true;
    }

    public function closeSplitModal(): void
    {
        $this->splitModalOpen = false;
    }

    public function splitBill(int $people, ?int $optionId): void
    {
        if ($optionId === null) {
            Notification::make()->title('Escolha a forma de pagamento padrão.')->danger()->send();

            return;
        }

        if ($this->guard(fn () => app(SplitSaleBill::class)($this->ensureSale(), $people, $optionId))) {
            $this->splitModalOpen = false;
        }
    }

    // ── Finalizar e cancelar ───────────────────────────────────────────

    public function finalizeSale(): void
    {
        $this->authorizeOperation();
        $this->resetErrorBag('finalize');

        if ($this->saleId === null) {
            // Nada aberto: ou a tela está vazia, ou o clique repetido chegou depois de finalizar.
            if ($this->finalizedSaleId === null) {
                $this->addError('finalize', 'Lance ao menos um pedido ou um item para finalizar a venda.');
            }

            return;
        }

        if ($this->sale === null) {
            Notification::make()->title('Esta venda já foi concluída ou cancelada em outra tela.')->warning()->send();
            $this->redirect(static::getUrl());

            return;
        }

        try {
            $sale = app(FinalizeOpenSale::class)($this->sale, auth()->user());
        } catch (SaleException $exception) {
            $this->addError('finalize', $exception->getMessage());
            $this->refreshState();

            return;
        }

        Notification::make()->title("Venda {$sale->displayNumber()} finalizada")->success()->send();

        $this->finalizedSaleId = $sale->id;
        $this->saleId = null;
        $this->refreshState();

        if (app(ShouldAutoIssue::class)(CurrentTenant::get(), $sale)) {
            $this->fiscalModalOpen = true;
            $this->fiscalStatus = 'processing';
            $this->fiscalError = null;
            $this->fiscalNotice = null;
            $this->fiscalPolls = 0;

            return;
        }

        $this->redirect(static::getUrl());
    }

    public function openCancelModal(): void
    {
        if ($this->sale === null) {
            $this->redirect(static::getUrl());

            return;
        }

        $this->cancellationNote = '';
        $this->cancelModalOpen = true;
    }

    public function closeCancelModal(): void
    {
        $this->cancelModalOpen = false;
    }

    public function confirmCancellation(): void
    {
        if (trim($this->cancellationNote) === '') {
            Notification::make()->title('Informe o motivo do cancelamento da venda.')->danger()->send();

            return;
        }

        $cancelled = $this->guard(function (): void {
            $sale = $this->sale ?? throw new SaleException('Venda não encontrada.');
            app(CancelSale::class)($sale, $this->cancellationNote, auth()->user());
        });

        if ($cancelled) {
            Notification::make()->title('Venda cancelada')->success()->send();
            $this->redirect(static::getUrl());
        }
    }

    // ── NFC-e no próprio modal ─────────────────────────────────────────

    /**
     * Chamado por `wire:poll` enquanto o modal está "processando": a emissão automática roda
     * depois da resposta e a NFE.io responde de forma assíncrona.
     */
    public function checkFiscalStatus(): void
    {
        $this->authorizeOperation();

        if ($this->fiscalStatus !== 'processing') {
            return;
        }

        $this->fiscalPolls++;
        $document = $this->finalizedSaleId === null ? null : FiscalDocument::query()->where('sale_id', $this->finalizedSaleId)->latest('id')->first();

        if ($document === null) {
            // A emissão automática pode ter sido recusada por uma regra (pendência fiscal, emissão
            // desligada) sem criar nota alguma: depois de um tempo o modal não pode girar para sempre.
            if ($this->fiscalPolls > self::FISCAL_MAX_POLLS) {
                $this->markFiscalError('A NFC-e não foi emitida automaticamente. Confira os dados fiscais e use "Enviar NFC-e" na tela da venda.');
            } elseif ($this->fiscalPolls > 20) {
                $this->fiscalNotice = 'A emissão está demorando mais que o normal. Você pode continuar: a nota segue pela tela da venda.';
            }

            return;
        }

        $this->fiscalDocumentId = $document->id;

        if ($document->status === FiscalDocumentStatus::Processing && filled($document->provider_id)) {
            try {
                $document = app(SyncFiscalDocumentStatus::class)($document);
            } catch (NfeIoException|FiscalDocumentException) {
                // Falha ao consultar não é falha da nota: o próximo poll tenta de novo (até o limite).
            }
        }

        $this->fiscalNotice = $document->buyer_omission_reason;
        $this->fiscalAmbiguous = $document->isAmbiguousSend();

        match (true) {
            $document->status === FiscalDocumentStatus::Issued => $this->fiscalStatus = 'issued',
            in_array($document->status, [FiscalDocumentStatus::Failed, FiscalDocumentStatus::FailedToSend, FiscalDocumentStatus::Cancelled], true),
            $document->status === FiscalDocumentStatus::Reserved,
            $document->isStaleSending() => $this->markFiscalError($document->error_message ?: "A NFE.io retornou o status '{$document->status->label()}' para esta nota."),
            $this->fiscalPolls > self::FISCAL_MAX_POLLS => $this->markFiscalError('A NFE.io ainda não confirmou a nota. Ela segue em processamento: acompanhe pela tela da venda.'),
            default => $this->fiscalStatus = 'processing',
        };
    }

    private function markFiscalError(string $message): void
    {
        $this->fiscalStatus = 'error';
        $this->fiscalError = $message;
    }

    public function retryFiscalIssue(): void
    {
        $this->authorizeOperation();
        // Emitir/reenviar é uma permissão própria (como na tela da venda): manage_sales não basta.
        abort_unless(auth()->user()?->can('emit_fiscal_document') ?? false, 403);

        $sale = $this->finalizedSaleId === null ? null : Sale::query()->find($this->finalizedSaleId);

        if ($sale === null) {
            return;
        }

        $this->fiscalStatus = 'processing';
        $this->fiscalError = null;
        $this->fiscalAmbiguous = false;
        $this->fiscalPolls = 0;

        try {
            app(IssueFiscalDocument::class)($sale, FiscalDocumentDecision::Manual, auth()->user());
        } catch (FiscalDocumentException|NfeIoException $exception) {
            $this->markFiscalError($exception->getMessage());
        }
    }

    public function openDanfe(): void
    {
        $this->authorizeOperation();

        $document = $this->fiscalDocumentId === null ? null : FiscalDocument::query()->find($this->fiscalDocumentId);

        if ($document === null || $document->status !== FiscalDocumentStatus::Issued || blank($document->provider_id)) {
            return;
        }

        $settings = TenantFiscalSettings::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $document->tenant_id)->first();

        if ($settings === null) {
            Notification::make()->title('Os dados fiscais do estabelecimento não foram encontrados')->danger()->send();

            return;
        }

        try {
            $url = app(NfeIoClient::class)->pdfUrl($settings, $document->provider_id);
        } catch (NfeIoException $exception) {
            Notification::make()->title('Não foi possível obter o DANFE')->body($exception->getMessage())->danger()->send();

            return;
        }

        if (! str_starts_with(strtolower($url), 'https://')) {
            Notification::make()->title('A NFE.io devolveu um endereço de DANFE inválido')->danger()->send();

            return;
        }

        $this->js('window.open('.json_encode($url).', "_blank")');
    }

    public function closeFiscalModal(): void
    {
        $this->fiscalModalOpen = false;
        $this->fiscalStatus = null;
        $this->fiscalError = null;
        $this->finalizedSaleId = null;
        $this->fiscalDocumentId = null;

        $this->redirect(static::getUrl());
    }
}
