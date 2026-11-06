<?php

namespace App\Filament\Tenant\Pages\Sales;

use App\Actions\Sales\FinalizeSale;
use App\Exceptions\SaleException;
use App\Filament\Support\InputMasks;
use App\Filament\Tenant\Resources\Sales\SaleResource;
use App\Models\CardBrand;
use App\Models\Order;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use App\Support\Sales\Money;
use App\Support\Sales\PaymentSummary;
use App\Support\Sales\StandaloneItemPrice;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;

/**
 * [Substituída pela Nova Venda — a URL redireciona; aguardando aprovação para apagar.]
 * Tela de fechamento de conta (oculta do menu; entra por "Contas a fechar" ou
 * "Venda avulsa"). Mostra os pedidos escolhidos, aceita itens avulsos e
 * pagamentos múltiplos e finaliza a venda por FinalizeSale. O resumo de
 * troco/falta é só PREVIEW — o servidor recalcula tudo ao finalizar.
 */
class CloseAccount extends Page
{
    protected static ?string $title = 'Fechar conta';

    protected static ?string $slug = 'fechar-conta';

    /** @var array<int, int> */
    #[Locked]
    public array $orderIds = [];

    /** Trava o reenvio (duplo clique) depois de a venda ser criada: venda só de itens avulsos não teria outra barreira. */
    #[Locked]
    public bool $finalized = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var Collection<int, PaymentOption>|null */
    private ?Collection $paymentOptionFlagsCache = null;

    private ?bool $usesRegisteredCardBrandsCache = null;

    /** @var array<int, string>|null */
    private ?array $cardBrandOptionsCache = null;

    public static function canAccess(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::PDV) ?? false)
            && (auth()->user()?->can('manage_sales') ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        // Tela substituída pela Nova Venda (Fase 1.R.2): a URL antiga redireciona e leva os
        // pedidos pedidos em `?orders=`. O restante da classe segue até a aprovação para apagá-la.
        $orders = (string) request()->query('orders', '');

        $this->redirect(NewSale::getUrl().($orders !== '' ? '?'.http_build_query(['orders' => $orders]) : ''));

        return;

        $requested = collect(explode(',', (string) request()->query('orders', '')))
            ->map(fn (string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $this->orderIds = $requested->isEmpty()
            ? []
            : Order::query()->awaitingSale()->whereIn('id', $requested)->orderBy('id')->pluck('id')->all();

        if (count($this->orderIds) !== $requested->count()) {
            Notification::make()
                ->title('Alguns pedidos não estão mais disponíveis para fechar (já cobrados ou cancelados).')
                ->warning()
                ->send();
        }

        $this->form->fill([
            'standalone_items' => [],
            'payments' => $this->declaredPayments(),
        ]);
    }

    public function getTitle(): string
    {
        return 'Fechar conta';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Resumo da conta')
                    ->schema([
                        Text::make(fn (): HtmlString => new HtmlString(
                            view('filament.tenant.pages.sales.close-account-summary', $this->summaryData())->render()
                        )),
                    ]),
                Section::make('Itens avulsos')
                    ->description('Produtos vendidos direto na conta, sem pedido.')
                    ->collapsed()
                    ->schema([
                        Repeater::make('standalone_items')
                            ->label('')
                            ->schema([
                                Select::make('product_id')
                                    ->label('Produto')
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search): array => Product::query()
                                        ->where('name', 'like', "%{$search}%")
                                        ->orderBy('name')
                                        ->limit(30)
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->getOptionLabelUsing(fn ($value): ?string => Product::query()->find($value)?->name)
                                    ->required()
                                    ->live(),
                                TextInput::make('quantity')
                                    ->label('Quantidade')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Adicionar item avulso')
                            ->reorderable(false)
                            ->live(),
                    ]),
                Section::make('Pagamentos recebidos')
                    ->description('Informe o valor recebido em cada forma; o troco é calculado só para pagamento em dinheiro.')
                    ->schema([
                        Repeater::make('payments')
                            ->label('')
                            ->schema([
                                Select::make('payment_option_id')
                                    ->label('Forma de pagamento')
                                    ->options(fn (): array => PaymentOption::query()->orderBy('name')->pluck('name', 'id')->all())
                                    ->required()
                                    ->live(),
                                InputMasks::money(
                                    TextInput::make('amount')
                                        ->label('Valor recebido')
                                        ->prefix('R$')
                                        ->required()
                                ),
                                Select::make('card_brand_id')
                                    ->label('Bandeira')
                                    ->options(fn (): array => $this->cardBrandOptions())
                                    ->required(fn (Get $get): bool => $this->optionRequires($get('payment_option_id'), 'requires_card_brand'))
                                    ->visible(fn (Get $get): bool => $this->usesRegisteredCardBrands()
                                        && ($this->isNonCashOption($get('payment_option_id')) || $this->optionRequires($get('payment_option_id'), 'requires_card_brand'))),
                                TextInput::make('card_brand')
                                    ->label('Bandeira')
                                    ->maxLength(60)
                                    ->required(fn (Get $get): bool => $this->optionRequires($get('payment_option_id'), 'requires_card_brand'))
                                    ->visible(fn (Get $get): bool => ! $this->usesRegisteredCardBrands()
                                        && ($this->isNonCashOption($get('payment_option_id')) || $this->optionRequires($get('payment_option_id'), 'requires_card_brand'))),
                                TextInput::make('authorization_code')
                                    ->label('Autorização')
                                    ->maxLength(100)
                                    ->required(fn (Get $get): bool => $this->optionRequires($get('payment_option_id'), 'requires_authorization_code'))
                                    ->visible(fn (Get $get): bool => $this->isNonCashOption($get('payment_option_id')) || $this->optionRequires($get('payment_option_id'), 'requires_authorization_code')),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Adicionar forma de pagamento')
                            ->reorderable(false)
                            ->live(),
                    ]),
            ])
            ->statePath('data');
    }

    public function finalize(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->finalized) {
            return;
        }

        $state = $this->form->getState();

        $payments = collect($state['payments'] ?? [])->map(fn (array $payment): array => [
            'payment_option_id' => (int) $payment['payment_option_id'],
            'amount' => InputMasks::normalizeMoneyState($payment['amount']),
            'card_brand' => $payment['card_brand'] ?? null,
            'card_brand_id' => $payment['card_brand_id'] ?? null,
            'authorization_code' => $payment['authorization_code'] ?? null,
        ])->all();

        $items = collect($state['standalone_items'] ?? [])->map(fn (array $item): array => [
            'product_id' => (int) $item['product_id'],
            'quantity' => (int) $item['quantity'],
        ])->all();

        try {
            $sale = app(FinalizeSale::class)($this->orderIds, $items, $payments, auth()->user());
        } catch (SaleException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        $this->finalized = true;

        Notification::make()->title("Venda {$sale->displayNumber()} finalizada")->success()->send();

        $this->redirect(SaleResource::canView($sale)
            ? SaleResource::getUrl('view', ['record' => $sale])
            : PendingAccounts::getUrl());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Voltar')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => PendingAccounts::getUrl()),
        ];
    }

    /**
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('finalize')
                ->label('Finalizar venda')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->submit('finalize'),
        ];
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('finalize')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment(Alignment::Start)
                    ->key('form-actions'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
        ]);
    }

    /**
     * Pré-preenche os pagamentos com o que o pedido declarou (somado por forma),
     * só quando o nome ainda existir entre as formas de pagamento do tenant.
     *
     * @return array<int, array{payment_option_id: int, amount: string}>
     */
    private function declaredPayments(): array
    {
        if ($this->orderIds === []) {
            return [];
        }

        $options = PaymentOption::query()->get()->keyBy('name');
        $declared = [];

        foreach (Order::query()->with('payments')->whereIn('id', $this->orderIds)->get() as $order) {
            foreach ($order->payments as $payment) {
                $declared[$payment->payment_option_name] = ($declared[$payment->payment_option_name] ?? 0) + Money::toCents($payment->amount);
            }
        }

        return collect($declared)
            ->filter(fn (int $cents, string $name): bool => $options->has($name) && $cents > 0)
            ->map(fn (int $cents, string $name): array => [
                'payment_option_id' => $options->get($name)->id,
                'amount' => Money::fromCents($cents),
            ])
            ->values()
            ->all();
    }

    /**
     * A forma de pagamento escolhida exige este dado (bandeira/autorização)?
     * Coluna booleana de `payment_options` (`requires_card_brand` ou
     * `requires_authorization_code`).
     */
    private function optionRequires(mixed $paymentOptionId, string $column): bool
    {
        return filled($paymentOptionId) && (bool) ($this->paymentOptionFlags()->get((int) $paymentOptionId)?->{$column});
    }

    /**
     * Com a feature `nfe_emissao` a bandeira vem do cadastro (CardBrand); sem ela, texto livre como antes.
     */
    private function usesRegisteredCardBrands(): bool
    {
        return $this->usesRegisteredCardBrandsCache ??= CurrentTenant::get()?->hasFeature(FeatureKey::NFE_EMISSAO) ?? false;
    }

    /**
     * @return array<int, string>
     */
    private function cardBrandOptions(): array
    {
        return $this->cardBrandOptionsCache ??= CardBrand::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    private function isNonCashOption(mixed $paymentOptionId): bool
    {
        $option = filled($paymentOptionId) ? $this->paymentOptionFlags()->get((int) $paymentOptionId) : null;

        return $option !== null && ! $option->is_cash;
    }

    /**
     * Flags das formas de pagamento do tenant, lidos UMA vez por requisição (as
     * closures de `required`/`visible` rodam várias vezes por linha do repeater).
     *
     * @return Collection<int, PaymentOption>
     */
    private function paymentOptionFlags(): Collection
    {
        return $this->paymentOptionFlagsCache ??= PaymentOption::query()
            ->get(['id', 'is_cash', 'requires_card_brand', 'requires_authorization_code'])
            ->keyBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryData(): array
    {
        $orders = $this->orderIds === []
            ? collect()
            : Order::query()->with('client')->whereIn('id', $this->orderIds)->orderBy('id')->get();

        $standalone = $this->standaloneLines();
        $ordersCents = $orders->sum(fn (Order $order): int => Money::toCents($order->grand_total));
        $totalCents = $ordersCents + $standalone->sum('total_cents');

        $summary = PaymentSummary::fromLines($totalCents, $this->paymentLines());

        return [
            'orders' => $orders->map(fn (Order $order): array => [
                'number' => $order->displayNumber(),
                'client' => $order->client?->name ?? 'Sem cliente',
                'status' => $order->status->label(),
                'total' => Money::format(Money::toCents($order->grand_total)),
            ]),
            'standalone' => $standalone->map(fn (array $line): array => [
                'name' => $line['name'],
                'quantity' => $line['quantity'],
                'unit' => Money::format($line['unit_cents']),
                'total' => Money::format($line['total_cents']),
            ]),
            'total' => Money::format($summary->totalCents),
            'received' => Money::format($summary->receivedCents),
            'remaining' => Money::format($summary->remainingCents()),
            'remainingCents' => $summary->remainingCents(),
            'change' => Money::format($summary->changeCents()),
            'changeBlocked' => ! $summary->canGiveChange(),
            'covered' => $summary->isCovered() && $summary->totalCents > 0,
        ];
    }

    /**
     * @return Collection<int, array{name: string, quantity: int, unit_cents: int, total_cents: int}>
     */
    private function standaloneLines(): Collection
    {
        $items = collect($this->data['standalone_items'] ?? [])
            ->filter(fn ($item): bool => is_array($item) && filled($item['product_id'] ?? null))
            ->groupBy(fn (array $item): int => (int) $item['product_id'])
            ->map(fn (Collection $group): int => max(0, (int) $group->sum(fn (array $item): int => (int) ($item['quantity'] ?? 0))));

        if ($items->isEmpty()) {
            return collect();
        }

        $products = Product::query()->whereIn('id', $items->keys())->get()->keyBy('id');
        $price = app(StandaloneItemPrice::class);

        return $items->map(function (int $quantity, int $productId) use ($products, $price): ?array {
            $product = $products->get($productId);

            if ($product === null || $quantity < 1) {
                return null;
            }

            $unit = Money::toCents($price($product));

            return ['name' => $product->name, 'quantity' => $quantity, 'unit_cents' => $unit, 'total_cents' => $unit * $quantity];
        })->filter()->values();
    }

    /**
     * @return array<int, array{amount_cents: int, is_cash: bool}>
     */
    private function paymentLines(): array
    {
        $lines = collect($this->data['payments'] ?? [])
            ->filter(fn ($line): bool => is_array($line) && filled($line['payment_option_id'] ?? null));

        if ($lines->isEmpty()) {
            return [];
        }

        $cash = PaymentOption::query()->whereIn('id', $lines->pluck('payment_option_id'))->pluck('is_cash', 'id');

        return $lines->map(fn (array $line): array => [
            'amount_cents' => max(0, Money::toCents(InputMasks::normalizeMoneyState($line['amount'] ?? 0))),
            'is_cash' => (bool) ($cash[(int) $line['payment_option_id']] ?? false),
        ])->values()->all();
    }
}
