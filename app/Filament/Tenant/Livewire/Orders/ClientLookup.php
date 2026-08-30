<?php

namespace App\Filament\Tenant\Livewire\Orders;

use App\Actions\Orders\FindOrCreateClient;
use App\Models\Client;
use App\Services\Address\ViaCepClient;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Busca/cadastro de cliente por telefone, espelhando app/Livewire/Checkout.php
 * (mesma normalização, mesmo ViaCepClient) — mas em vez de guardar o estado
 * só localmente, propaga cada mudança pro pai (AttendOrder) via evento,
 * já que aqui é um componente Livewire "solto" dentro da Page do Filament.
 * Exclusivo do painel interno: também permite marcar "Pedido sem cliente"
 * e buscar um cliente já cadastrado por nome (além de telefone).
 */
class ClientLookup extends Component
{
    public string $phone = '';

    public string $name = '';

    public ?string $zipCode = null;

    public ?string $street = null;

    public ?string $number = null;

    public ?string $complement = null;

    public ?string $neighborhood = null;

    public ?string $city = null;

    public ?string $state = null;

    public bool $clientFound = false;

    public bool $cepNotFound = false;

    public bool $withoutClient = false;

    public bool $searchModalOpen = false;

    public string $searchQuery = '';

    /**
     * @param  array<string, mixed>  $initial
     */
    public function mount(array $initial = []): void
    {
        $this->phone = $initial['phone'] ?? '';
        $this->name = $initial['name'] ?? '';
        $this->zipCode = $initial['zip_code'] ?? null;
        $this->street = $initial['street'] ?? null;
        $this->number = $initial['number'] ?? null;
        $this->complement = $initial['complement'] ?? null;
        $this->neighborhood = $initial['neighborhood'] ?? null;
        $this->city = $initial['city'] ?? null;
        $this->state = $initial['state'] ?? null;
        $this->withoutClient = $initial['without_client'] ?? false;
        $this->clientFound = $this->phone !== '';

        $this->emitChange();
    }

    public function toggleWithoutClient(): void
    {
        $this->withoutClient = ! $this->withoutClient;
        $this->emitChange();
    }

    /**
     * Busca automática assim que o telefone parece completo — mesmo gatilho
     * do Checkout público (10 dígitos fixo, 11 celular).
     */
    public function updatedPhone(): void
    {
        $normalized = app(FindOrCreateClient::class)->normalizePhone($this->phone);

        if (strlen($normalized) >= 10) {
            $this->lookupClient();
        } else {
            $this->clientFound = false;
        }

        $this->emitChange();
    }

    public function lookupClient(): void
    {
        $phone = app(FindOrCreateClient::class)->normalizePhone($this->phone);

        if ($phone === '') {
            return;
        }

        $client = Client::where('phone', $phone)->first();

        if ($client) {
            $this->fillFromClient($client);
        } else {
            $this->clientFound = false;
        }

        $this->emitChange();
    }

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

        $this->emitChange();
    }

    public function openSearchModal(): void
    {
        $this->searchQuery = '';
        $this->searchModalOpen = true;
    }

    public function closeSearchModal(): void
    {
        $this->searchModalOpen = false;
    }

    public function selectClient(int $clientId): void
    {
        $client = Client::find($clientId);

        if ($client === null) {
            return;
        }

        $this->fillFromClient($client);
        $this->searchModalOpen = false;
        $this->emitChange();
    }

    private function fillFromClient(Client $client): void
    {
        $this->phone = $client->phone;
        $this->name = $client->name;
        $this->zipCode = $client->zip_code;
        $this->street = $client->street;
        $this->number = $client->number;
        $this->complement = $client->complement;
        $this->neighborhood = $client->neighborhood;
        $this->city = $client->city;
        $this->state = $client->state;
        $this->clientFound = true;
    }

    /**
     * Busca por nome OU telefone (dígitos) — usada no modal de busca do
     * item 4 (diferente de lookupClient(), que é a busca automática por
     * telefone completo ao digitar).
     *
     * @return Collection<int, Client>
     */
    #[Computed]
    public function searchResults(): Collection
    {
        if (blank($this->searchQuery)) {
            return collect();
        }

        $digits = preg_replace('/\D+/', '', $this->searchQuery);

        return Client::query()
            ->where('name', 'like', "%{$this->searchQuery}%")
            ->when($digits !== '', fn ($query) => $query->orWhere('phone', 'like', "%{$digits}%"))
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    /**
     * Hook genérico do Livewire — cobre os campos ligados via wire:model.live
     * que não têm método updated{Campo} dedicado (nome, rua, número, etc.).
     */
    public function updated(string $name): void
    {
        $this->emitChange();
    }

    private function emitChange(): void
    {
        $this->dispatch('order-client-data-changed', data: [
            'phone' => $this->phone,
            'name' => $this->name,
            'zip_code' => $this->zipCode,
            'street' => $this->street,
            'number' => $this->number,
            'complement' => $this->complement,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'state' => $this->state,
            'without_client' => $this->withoutClient,
        ]);
    }

    public function render()
    {
        return view('filament.tenant.livewire.orders.client-lookup');
    }
}
