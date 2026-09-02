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
use App\Models\City;
use App\Models\Client;
use App\Models\DeliveryOption;
use App\Models\DeliveryZoneNeighborhood;
use App\Models\Neighborhood;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\State;
use App\Rules\ValidCpf;
use App\Services\Address\ViaCepClient;
use App\Services\Security\RecaptchaVerifier;
use App\Support\Cart;
use App\Support\CurrentTenant;
use App\Support\NeighborhoodNormalizer;
use Illuminate\Support\Collection;
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

    /**
     * Cidade do catálogo global escolhida no select (modo restrito). Deriva
     * `$city` (nome) e alimenta as opções de bairro. Null no modo texto livre.
     */
    public ?int $cityId = null;

    /**
     * Endereço só aparece depois que o cliente informa o CEP (achou ou não)
     * ou clica em "Não sei meu CEP" — força o uso do campo de CEP primeiro.
     */
    public bool $addressUnlocked = false;

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

    /**
     * Fica true assim que o cliente digita algo no valor da 1ª forma de
     * pagamento — a partir daí paramos de espelhar o total do pedido nesse
     * campo automaticamente (ver autofillRemaining()).
     */
    public bool $paymentAmountManuallySet = false;

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
        if ($name === 'payments.0.amount') {
            $this->paymentAmountManuallySet = true;
        }

        if (str_starts_with($name, 'payments.') && str_ends_with($name, '.amount')) {
            $this->autofillRemaining();
        }
    }

    /**
     * Mantém o valor a pagar em dia com o total do pedido, igual ao
     * FulfillmentPicker do painel:
     * - Uma única forma de pagamento, ainda não editada à mão: espelha o
     *   total atual (que muda conforme a entrega/endereço escolhidos, sem
     *   passar por updated()).
     * - Pagamento dividido: preenche o saldo restante na 1ª linha em branco.
     */
    private function autofillRemaining(): void
    {
        if ($this->grandTotal <= 0) {
            return;
        }

        if (count($this->payments) === 1 && ! $this->paymentAmountManuallySet) {
            $this->payments[0]['amount'] = number_format($this->grandTotal, 2, ',', '.');

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

            if (filled($client->street) || filled($client->neighborhood)) {
                $this->addressUnlocked = true;
                $this->syncCityIdFromNames();
            }
        } else {
            $this->clientFound = false;
        }
    }

    /**
     * Botão "Não sei meu CEP" — revela os campos de endereço (selects de
     * estado/cidade/bairro no modo restrito, texto livre no modo padrão).
     */
    public function revealManualAddress(): void
    {
        $this->addressUnlocked = true;
    }

    public function updatedState(): void
    {
        $this->cityId = null;
        $this->city = null;
        $this->neighborhood = null;
    }

    public function updatedCityId(): void
    {
        $city = $this->cityId ? City::find($this->cityId) : null;
        $this->city = $city?->name;
        $this->state = $city?->state?->uf ?? $this->state;
        $this->neighborhood = null;
    }

    /**
     * No modo restrito, tenta casar a cidade/UF já preenchidas (via cliente
     * ou ViaCEP) a uma das cidades atendidas, para pré-selecionar o select.
     */
    private function syncCityIdFromNames(): void
    {
        if (! $this->addressIsRestricted || blank($this->city)) {
            return;
        }

        $normalizedCity = NeighborhoodNormalizer::normalize($this->city);

        $this->cityId = $this->servedCities
            ->first(fn (City $city) => $city->normalized_name === $normalizedCity
                && (blank($this->state) || $city->state?->uf === $this->state))
            ?->id;
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

        // Achou ou não, o cliente já usou o campo de CEP — libera o resto do
        // endereço (no "não achou" ele preenche/escolhe manualmente).
        $this->addressUnlocked = true;

        if ($address === null) {
            $this->cepNotFound = true;

            return;
        }

        $this->street = $address['street'] ?? $this->street;
        $this->city = $address['city'] ?? $this->city;
        $this->state = $address['state'] ?? $this->state;

        if ($this->addressIsRestricted) {
            $this->syncCityIdFromNames();
            // Pré-seleciona o bairro só se ele existir no catálogo da cidade.
            $viaCepNeighborhood = $address['neighborhood'] ?? null;
            $this->neighborhood = $viaCepNeighborhood !== null && in_array($viaCepNeighborhood, $this->neighborhoodOptions, true)
                ? $viaCepNeighborhood
                : null;
        } else {
            $this->neighborhood = $address['neighborhood'] ?? $this->neighborhood;
        }
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
            'state.required' => 'Escolha o estado da entrega.',
            'city.required' => 'Escolha a cidade da entrega.',
            'street.required' => 'Informe a rua para entrega.',
            'neighborhood.required' => 'Informe o bairro para entrega.',
            'complement.required' => 'Sem número? Informe um complemento ou ponto de referência.',
        ];

        if ($this->requiresCpf) {
            $rules['cpf'] = ['required', 'string', new ValidCpf];
        }

        if ($this->requiresAddress) {
            // Força o cliente a começar pelo CEP: enquanto o endereço não foi
            // destravado (CEP consultado ou "Não sei meu CEP"), nem valida o
            // resto — só pede o CEP.
            if (! $this->addressUnlocked) {
                $this->errorMessage = 'Informe o endereço de entrega — comece pelo CEP.';
                $this->errorSection = 'delivery';
                $this->dispatch('checkout-validation-failed', field: 'zipCode');

                return;
            }

            // Ordem das regras = ordem visual dos campos, para o
            // checkout-validation-failed focar o 1º campo pendente que o
            // cliente realmente está vendo.
            $addressRules = [];

            if ($this->addressIsRestricted) {
                $addressRules['state'] = ['required', 'string'];
                $addressRules['city'] = ['required', 'string'];
                $addressRules['neighborhood'] = ['required', 'string', 'max:255'];
                $addressRules['street'] = ['required', 'string', 'max:255'];
            } else {
                $addressRules['street'] = ['required', 'string', 'max:255'];
                $addressRules['neighborhood'] = ['required', 'string', 'max:255'];
            }

            $addressRules['number'] = ['nullable', 'string', 'max:20'];
            // Número pode ser "S/N" ou ficar em branco — mas aí o complemento
            // (ponto de referência) vira obrigatório, senão o endereço fica
            // impossível de localizar.
            $addressRules['complement'] = [blank($this->number) ? 'required' : 'nullable', 'string', 'max:255'];

            $rules += $addressRules;
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

        $giftIds = collect($items)->flatMap(fn (array $item) => $item['gifts'] ?? [])->pluck('gift_product_id')->unique()->values();
        $giftNames = $giftIds->isEmpty() ? collect() : Product::whereIn('id', $giftIds)->pluck('name', 'id');

        $productIds = collect($items)
            ->flatMap(fn (array $item) => [$item['product_id'] ?? null, ...($item['flavor_ids'] ?? [])])
            ->filter()
            ->unique()
            ->values();

        $products = $productIds->isEmpty()
            ? collect()
            : Product::with('category:id,name')->whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($items as $index => $item) {
            try {
                $resolved = $resolve($item);
            } catch (InvalidArgumentException) {
                continue;
            }

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
                ->map(fn (array $gift) => "🎁 {$gift['quantity']}x ".($giftNames->get($gift['gift_product_id']) ?? 'Brinde removido').' — grátis')
                ->values()
                ->all();

            $lines[] = [
                'index' => $index,
                'name' => $name,
                'category_name' => $mainProduct?->category?->name,
                'image_url' => $mainProduct?->image_url,
                'quantity' => $item['quantity'],
                'note' => $item['note'] ?? null,
                'unit_price' => $resolved['unit_price'],
                'addons_total' => $resolved['addons_total'],
                'addons_display' => $addonsDisplay,
                'gifts_display' => $giftsDisplay,
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
     * Modo restrito: o cliente escolhe cidade/bairro em listas em vez de
     * digitar. Só quando o tenant desligou "digitar endereço livremente" E
     * tem setores de entrega cadastrados (sem setores não há o que listar).
     */
    #[Computed]
    public function addressIsRestricted(): bool
    {
        $tenant = CurrentTenant::get();

        return $tenant !== null
            && ! ($tenant->allow_free_form_address ?? true)
            && $tenant->deliveryZones()->exists();
    }

    /**
     * Cidades que o tenant atende (têm bairro em algum setor de entrega),
     * resolvidas contra o catálogo global via `city_id`.
     *
     * @return Collection<int, City>
     */
    #[Computed]
    public function servedCities(): Collection
    {
        $cityIds = DeliveryZoneNeighborhood::query()
            ->whereNotNull('city_id')
            ->distinct()
            ->pluck('city_id');

        return City::query()
            ->whereIn('id', $cityIds)
            ->with('state')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, State>
     */
    #[Computed]
    public function servedStates(): Collection
    {
        return $this->servedCities
            ->pluck('state')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /**
     * @return Collection<int, City>
     */
    #[Computed]
    public function cityOptionsForState(): Collection
    {
        return $this->servedCities
            ->filter(fn (City $city) => blank($this->state) || $city->state?->uf === $this->state)
            ->values();
    }

    /**
     * Bairros do catálogo global da cidade escolhida — lista todos os bairros
     * reais da cidade (o casamento com um setor, ou a taxa de bairro não
     * mapeado, acontece depois em ResolveDeliveryFee).
     *
     * @return array<string, string>
     */
    #[Computed]
    public function neighborhoodOptions(): array
    {
        if ($this->cityId === null) {
            return [];
        }

        return Neighborhood::query()
            ->where('city_id', $this->cityId)
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
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

        return $this->deliveryFeeResolution === null
            ? 0.0
            : ($this->deliveryFeeResolution['fee'] ?? 0.0);
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
        // O total só fica conhecido depois do mount (depende do carrinho e da
        // opção de entrega escolhida), e mudar de entrega não passa por
        // updating/updated — então o preenchimento do saldo restante é
        // reavaliado aqui a cada render, igual ao FulfillmentPicker do painel.
        $this->autofillRemaining();

        return view('livewire.checkout')
            ->layout('components.layouts.public', ['tenant' => CurrentTenant::get()]);
    }
}
