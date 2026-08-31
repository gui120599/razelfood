<?php

namespace App\Livewire;

use App\Actions\Menu\ResolvePriceForCartLine;
use App\Actions\Orders\BuildWhatsAppMessage;
use App\Actions\Orders\CreateOrderFromCart;
use App\Actions\Orders\FindOrCreateClient;
use App\Actions\Orders\ResolveDeliveryFee;
use App\Exceptions\CheckoutException;
use App\Filament\Support\InputMasks;
use App\Livewire\Concerns\EstablishesTenantContext;
use App\Models\Addon;
use App\Models\Client;
use App\Models\DeliveryOption;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Rules\ValidCpf;
use App\Services\Address\ViaCepClient;
use App\Services\Security\RecaptchaVerifier;
use App\Support\Cart;
use App\Support\CurrentTenant;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Checkout extends Component
{
    use EstablishesTenantContext;

    public string $phone = '';

    public string $name = '';

    public string $cpf = '';

    public ?string $zipCode = null;

    public ?string $street = null;

    public ?string $number = null;

    public ?string $complement = null;

    public ?string $neighborhood = null;

    public ?string $city = null;

    public ?string $state = null;

    public ?int $deliveryOptionId = null;

    /** @var array<int, array{payment_option_id: ?int, amount: ?string, change_for: ?string}> Cada linha combina uma forma de pagamento com o valor pago nela — permite dividir o pagamento em mais de uma forma. */
    public array $payments = [];

    public ?string $notes = null;

    /**
     * Token do reCAPTCHA gerado no cliente (v3) e preenchido pelo Alpine
     * imediatamente antes de chamar submit() — só é usado quando o tenant
     * tem `recaptcha_enabled` (RN-29). Nunca é fonte de verdade: o servidor
     * revalida em RecaptchaVerifier.
     */
    public ?string $recaptchaToken = null;

    public bool $clientFound = false;

    public bool $cepNotFound = false;

    public ?string $errorMessage = null;

    /**
     * Em qual section o banner de $errorMessage deve aparecer — 'payment',
     * 'delivery', 'items' ou null (sem section específica, ex.: fora do
     * horário de funcionamento, fica num banner de fallback perto do botão
     * de enviar). Erro de campo obrigatório (telefone/nome/endereço) não
     * usa isso — já é inline por campo + rola/foca (checkout-validation-failed).
     */
    public ?string $errorSection = null;

    public function mount(): void
    {
        $this->payments = [$this->blankPaymentLine()];
    }

    private function blankPaymentLine(): array
    {
        return ['payment_option_id' => null, 'amount' => null, 'change_for' => null];
    }

    public function addPaymentLine(): void
    {
        $this->payments[] = $this->blankPaymentLine();
        $this->autofillRemaining();
    }

    public function removePaymentLine(int $index): void
    {
        if (count($this->payments) <= 1) {
            return;
        }

        unset($this->payments[$index]);
        $this->payments = array_values($this->payments);
        $this->autofillRemaining();
    }

    public function updated(string $name): void
    {
        if (str_starts_with($name, 'payments.') && str_ends_with($name, '.amount')) {
            $this->autofillRemaining();
        }
    }

    /**
     * Preenche automaticamente o saldo restante (total - soma já digitada)
     * na primeira linha de pagamento ainda em branco, sempre que a soma
     * digitada ainda não bate com o total.
     */
    private function autofillRemaining(): void
    {
        if ($this->grandTotal <= 0) {
            return;
        }

        $sumFilled = 0.0;
        $blankIndex = null;

        foreach ($this->payments as $index => $line) {
            if (blank($line['amount'] ?? null)) {
                $blankIndex ??= $index;

                continue;
            }

            $sumFilled += $this->parseBrl($line['amount']);
        }

        if ($blankIndex === null) {
            return;
        }

        $remaining = round($this->grandTotal - $sumFilled, 2);

        if ($remaining > 0) {
            $this->payments[$blankIndex]['amount'] = number_format($remaining, 2, ',', '.');
        }
    }

    /**
     * Ao escolher a forma de pagamento de uma linha: zera o troco anterior
     * (só relevante pra dinheiro, e pode ter sido preenchido pra outra
     * opção antes de trocar).
     */
    public function selectPaymentOptionForLine(int $index, int $id): void
    {
        if (! isset($this->payments[$index])) {
            return;
        }

        $this->payments[$index]['payment_option_id'] = $id;
        $this->payments[$index]['change_for'] = null;
    }

    public function isLineCash(int $index): bool
    {
        $id = $this->payments[$index]['payment_option_id'] ?? null;

        return $id ? (bool) PaymentOption::find($id)?->is_cash : false;
    }

    /**
     * Busca automática assim que o telefone parece completo (10 dígitos =
     * fixo, 11 = celular com 9º dígito) — não espera o campo perder foco.
     */
    public function updatedPhone(): void
    {
        $normalized = app(FindOrCreateClient::class)->normalizePhone($this->phone);

        if (strlen($normalized) >= 10) {
            $this->lookupClient();
        } else {
            $this->clientFound = false;
        }
    }

    public function lookupClient(): void
    {
        $phone = app(FindOrCreateClient::class)->normalizePhone($this->phone);

        if ($phone === '') {
            return;
        }

        $client = Client::where('phone', $phone)->first();

        if ($client) {
            $this->name = $client->name;
            $this->cpf = InputMasks::formatCpf($client->cpf) ?? '';
            $this->zipCode = $client->zip_code;
            $this->street = $client->street;
            $this->number = $client->number;
            $this->complement = $client->complement;
            $this->neighborhood = $client->neighborhood;
            $this->city = $client->city;
            $this->state = $client->state;
            $this->clientFound = true;
        } else {
            $this->clientFound = false;
        }
    }

    /**
     * RN-33: busca auxiliar de endereço por CEP (ViaCEP) — se não encontrar
     * ou o serviço externo falhar, não bloqueia nada, o cliente preenche
     * manualmente.
     */
    public function lookupCep(): void
    {
        $this->cepNotFound = false;

        if (blank($this->zipCode)) {
            return;
        }

        $address = app(ViaCepClient::class)->lookup($this->zipCode);

        if ($address === null) {
            $this->cepNotFound = true;

            return;
        }

        $this->street = $address['street'] ?? $this->street;
        $this->neighborhood = $address['neighborhood'] ?? $this->neighborhood;
        $this->city = $address['city'] ?? $this->city;
        $this->state = $address['state'] ?? $this->state;
    }

    #[Computed]
    public function recaptchaEnabled(): bool
    {
        return (bool) (CurrentTenant::get()?->recaptcha_enabled ?? false)
            && filled(CurrentTenant::get()?->recaptcha_site_key)
            && filled(CurrentTenant::get()?->recaptcha_secret_key);
    }

    #[Computed]
    public function recaptchaSiteKey(): ?string
    {
        return CurrentTenant::get()?->recaptcha_site_key;
    }

    public function submit(): void
    {
        $this->errorMessage = null;
        $this->errorSection = null;

        $rules = [
            'phone' => ['required', 'string', 'min:10'],
            'name' => ['required', 'string', 'max:255'],
        ];

        $messages = [
            'phone.required' => 'Informe seu telefone.',
            'name.required' => 'Informe seu nome.',
            'cpf.required' => 'Informe seu CPF.',
            'street.required' => 'Informe a rua para entrega.',
            'neighborhood.required' => 'Informe o bairro para entrega.',
            'complement.required' => 'Sem número? Informe um complemento ou ponto de referência.',
        ];

        if ($this->requiresCpf) {
            $rules['cpf'] = ['required', 'string', new ValidCpf];
        }

        if ($this->requiresAddress) {
            $rules['street'] = ['required', 'string', 'max:255'];
            $rules['neighborhood'] = ['required', 'string', 'max:255'];
            $rules['number'] = ['nullable', 'string', 'max:20'];
            // Número pode ser "S/N" ou ficar em branco — mas aí o complemento
            // (ponto de referência) vira obrigatório, senão o endereço fica
            // impossível de localizar.
            $rules['complement'] = [blank($this->number) ? 'required' : 'nullable', 'string', 'max:255'];
        }

        try {
            $this->validate($rules, $messages);
        } catch (ValidationException $e) {
            $this->dispatch('checkout-validation-failed', field: array_key_first($e->errors()));

            throw $e;
        }

        // Conveniência: com só 1 forma de pagamento, o valor é implicitamente
        // o total do pedido — não obriga o cliente a digitar o óbvio.
        if (count($this->payments) === 1 && blank($this->payments[0]['amount'] ?? null)) {
            $this->payments[0]['amount'] = number_format($this->grandTotal, 2, ',', '.');
        }

        if (collect($this->payments)->contains(fn (array $p) => blank($p['payment_option_id'] ?? null) || blank($p['amount'] ?? null))) {
            $this->errorMessage = 'Escolha uma forma de pagamento e informe o valor de cada parcela.';
            $this->errorSection = 'payment';

            return;
        }

        $paymentsSum = collect($this->payments)->sum(fn (array $p) => $this->parseBrl($p['amount']));

        if (abs($paymentsSum - $this->grandTotal) > 0.01) {
            $this->errorMessage = 'A soma das formas de pagamento (R$ '.number_format($paymentsSum, 2, ',', '.').') precisa bater com o total do pedido (R$ '.number_format($this->grandTotal, 2, ',', '.').').';
            $this->errorSection = 'payment';

            return;
        }

        if ($this->recaptchaEnabled) {
            $verified = app(RecaptchaVerifier::class)->verify(
                $this->recaptchaToken,
                (string) CurrentTenant::get()->recaptcha_secret_key,
            );

            $this->recaptchaToken = null;

            if (! $verified) {
                $this->errorMessage = 'Não foi possível confirmar que você não é um robô. Recarregue a página e tente novamente.';
                $this->errorSection = null;

                return;
            }
        }

        try {
            $order = app(CreateOrderFromCart::class)(Cart::items(), [
                'phone' => $this->phone,
                'name' => $this->name,
                'cpf' => $this->requiresCpf ? $this->cpf : null,
                'zip_code' => $this->zipCode,
                'street' => $this->street,
                'number' => $this->number,
                'complement' => $this->complement,
                'neighborhood' => $this->neighborhood,
                'city' => $this->city,
                'state' => $this->state,
                'delivery_option_id' => $this->deliveryOptionId,
                'payments' => collect($this->payments)->map(fn (array $p) => [
                    'payment_option_id' => (int) $p['payment_option_id'],
                    'amount' => $this->parseBrl($p['amount']),
                    'change_for' => blank($p['change_for'] ?? null) ? null : $this->parseBrl($p['change_for']),
                ])->all(),
                'notes' => blank($this->notes) ? null : $this->notes,
            ]);
        } catch (CheckoutException|InvalidArgumentException $e) {
            $this->errorMessage = $e->getMessage();
            $this->errorSection = $this->classifyErrorSection($e->getMessage());

            return;
        }

        Cart::clear();

        $message = app(BuildWhatsAppMessage::class)($order);
        $tenant = CurrentTenant::get();
        $whatsappUrl = 'https://wa.me/'.preg_replace('/\D+/', '', $tenant->whatsapp_number).'?text='.rawurlencode($message);

        $this->redirect($whatsappUrl, navigate: false);
    }

    /**
     * Classifica a mensagem de CheckoutException/InvalidArgumentException
     * lançada por CreateOrderFromCart pra section correspondente do form —
     * conjunto fechado de mensagens que a própria Action/Ledger lançam (ver
     * grep em CreateOrderFromCart/CartStockAndPromotionLedger/ResolveDeliveryFee),
     * não é sniffing de texto arbitrário. Mensagem sem match conhecido (ex.:
     * fora do horário de funcionamento, texto livre configurado pelo tenant)
     * cai em null — banner de fallback perto do botão de enviar.
     */
    private function classifyErrorSection(string $message): ?string
    {
        return match (true) {
            str_contains($message, 'forma de pagamento') => 'payment',
            str_contains($message, 'estoque'), str_contains($message, 'promoç'), str_contains($message, 'carrinho') => 'items',
            str_contains($message, 'entrega'), str_contains($message, 'bairro') => 'delivery',
            default => null,
        };
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

    #[Computed]
    public function cartLines(): array
    {
        $resolve = app(ResolvePriceForCartLine::class);
        $items = Cart::items();
        $lines = [];

        $addonIds = collect($items)->flatMap(fn (array $item) => $item['addons'] ?? [])->pluck('addon_id')->unique()->values();
        $addonNames = $addonIds->isEmpty() ? collect() : Addon::whereIn('id', $addonIds)->pluck('name', 'id');
        $flavorIds = collect($items)->flatMap(fn (array $item) => $item['flavor_ids'] ?? [])->unique();
        $flavorNames = $flavorIds->isEmpty() ? collect() : Product::whereIn('id', $flavorIds)->pluck('name', 'id');

        foreach ($items as $index => $item) {
            try {
                $resolved = $resolve($item);
            } catch (InvalidArgumentException) {
                continue;
            }

            $name = $item['type'] === 'combo'
                ? Product::whereIn('id', $item['flavor_ids'])->pluck('name')->implode(' / ')
                : (Product::find($item['product_id'])?->name ?? 'Produto removido');

            $categoryProductId = $item['type'] === 'combo' ? ($item['flavor_ids'][0] ?? null) : $item['product_id'];
            $categoryName = $categoryProductId ? Product::find($categoryProductId)?->category?->name : null;

            $addonsDisplay = collect($item['addons'] ?? [])->map(function (array $selection) use ($addonNames, $flavorNames) {
                $addonName = $addonNames->get($selection['addon_id'], 'Adicional removido');
                $target = $selection['target'] !== null ? ($flavorNames->get($selection['target']) ?? 'sabor removido') : 'produto inteiro';

                return "{$selection['quantity']}x {$addonName} ({$target})";
            })->all();

            $lines[] = [
                'index' => $index,
                'name' => $name,
                'category_name' => $categoryName,
                'quantity' => $item['quantity'],
                'note' => $item['note'] ?? null,
                'unit_price' => $resolved['unit_price'],
                'addons_total' => $resolved['addons_total'],
                'addons_display' => $addonsDisplay,
                'line_total' => round(($resolved['unit_price'] + $resolved['addons_total']) * $item['quantity'], 2),
            ];
        }

        return $lines;
    }

    #[Computed]
    public function itemsTotal(): float
    {
        return round(array_sum(array_column($this->cartLines, 'line_total')), 2);
    }

    /**
     * A opção escolhida em "Como deseja receber?", se houver.
     */
    #[Computed]
    public function selectedDeliveryOption(): ?DeliveryOption
    {
        return $this->deliveryOptionId ? DeliveryOption::find($this->deliveryOptionId) : null;
    }

    /**
     * Só opções de entrega de verdade (ex.: motoboy) pedem endereço —
     * "Retirada", "Comer no Local" etc. ficam com requires_address=false.
     */
    #[Computed]
    public function requiresAddress(): bool
    {
        return (bool) $this->selectedDeliveryOption?->requires_address;
    }

    /**
     * RN: quando o tenant liga "Exigir CPF" em Configurações de Pedidos, o
     * campo de CPF aparece no checkout e vira obrigatório. Só o checkout
     * público — a Central de Pedidos não é afetada.
     */
    #[Computed]
    public function requiresCpf(): bool
    {
        return (bool) CurrentTenant::get()?->require_client_cpf;
    }

    /**
     * Preview do lado do cliente, só para exibição — a taxa real é sempre
     * recalculada no servidor em CreateOrderFromCart (RN-13, RN-37). Não
     * bloqueia a digitação: enquanto o bairro não bate com nenhum setor e
     * o cliente ainda não terminou de preencher o endereço, não mostra erro.
     *
     * @return array{fee: float, is_unlisted_neighborhood: bool, base_fee: float, unlisted_surcharge: float, blocked: bool, message: ?string}|null null = não se aplica (retirada/sem endereço, ou bairro ainda não informado)
     */
    #[Computed]
    public function deliveryFeeResolution(): ?array
    {
        $option = $this->selectedDeliveryOption;

        if (! $option || ! $option->requires_address || blank($this->neighborhood)) {
            return null;
        }

        try {
            $resolved = app(ResolveDeliveryFee::class)($option, $this->neighborhood, $this->city, $this->itemsTotal);

            return [...$resolved, 'blocked' => false, 'message' => null];
        } catch (CheckoutException $e) {
            return ['fee' => 0.0, 'delivery_zone_id' => null, 'is_unlisted_neighborhood' => false, 'base_fee' => 0.0, 'unlisted_surcharge' => 0.0, 'blocked' => true, 'message' => $e->getMessage()];
        }
    }

    #[Computed]
    public function deliveryFee(): float
    {
        $option = $this->selectedDeliveryOption;

        if (! $option) {
            return 0.0;
        }

        // Opção sem endereço (retirada, consumo no local): taxa fixa da
        // própria opção, mesma regra usada em CreateOrderFromCart.
        if (! $option->requires_address) {
            if ($option->min_order_for_free_delivery !== null && $this->itemsTotal >= $option->min_order_for_free_delivery) {
                return 0.0;
            }

            return (float) $option->delivery_fee;
        }

        return $this->deliveryFeeResolution['fee'] ?? 0.0;
    }

    #[Computed]
    public function grandTotal(): float
    {
        return round($this->itemsTotal + $this->deliveryFee, 2);
    }

    #[Computed]
    public function deliveryOptions()
    {
        return DeliveryOption::where('show_in_menu', true)->orderBy('name')->get();
    }

    #[Computed]
    public function paymentOptions()
    {
        return PaymentOption::where('show_in_menu', true)->orderBy('name')->get();
    }

    public function render()
    {
        return view('livewire.checkout')
            ->layout('components.layouts.public', ['tenant' => CurrentTenant::get()]);
    }
}
