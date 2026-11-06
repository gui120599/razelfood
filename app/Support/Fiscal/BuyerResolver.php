<?php

namespace App\Support\Fiscal;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Order;
use App\Models\Sale;
use App\Models\Scopes\TenantScope;
use App\Rules\ValidCnpj;
use App\Rules\ValidCpf;
use Illuminate\Support\Facades\Validator;

/**
 * Decide se a NFC-e leva comprador identificado (RN-FIS-10): só quando TODOS os
 * pedidos da venda são de um mesmo cliente, com nome e CPF/CNPJ válidos.
 * Qualquer dúvida omite o comprador (consumidor não identificado) e devolve o
 * motivo, para ficar registrado no documento.
 */
class BuyerResolver
{
    /**
     * @return array{buyer: ?array<string, mixed>, reason: ?string}
     */
    public function __invoke(Sale $sale): array
    {
        $orders = Order::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $sale->tenant_id)
            ->where('sale_id', $sale->id)
            ->get(['id', 'client_id']);

        if ($orders->isEmpty()) {
            return $this->omit('Venda sem pedidos: consumidor não identificado.');
        }

        $clientIds = $orders->pluck('client_id')->unique()->values();

        if ($clientIds->count() > 1) {
            return $this->omit('Pedidos de clientes diferentes: consumidor não identificado.');
        }

        if ($clientIds->first() === null) {
            return $this->omit('Pedido sem cliente cadastrado: consumidor não identificado.');
        }

        $client = Client::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $sale->tenant_id)
            ->find($clientIds->first());

        if ($client === null || blank($client->name)) {
            return $this->omit('Cliente sem nome cadastrado: consumidor não identificado.');
        }

        $isCompany = $client->person_type === PersonType::Company;
        $document = preg_replace('/\D/', '', (string) ($isCompany ? $client->cnpj : $client->cpf));

        if ($document === '') {
            return $this->omit('Cliente sem '.($isCompany ? 'CNPJ' : 'CPF').' cadastrado: consumidor não identificado.');
        }

        $rule = $isCompany ? new ValidCnpj : new ValidCpf;

        if (Validator::make(['document' => $document], ['document' => [$rule]])->fails()) {
            return $this->omit('Cliente com '.($isCompany ? 'CNPJ' : 'CPF').' inválido: consumidor não identificado.');
        }

        $buyer = [
            'stateTaxNumberIndicator' => 'NonTaxPayer',
            'tradeName' => $client->name,
            'taxRegime' => 'isento',
            'id' => (string) $client->id,
            'name' => $client->name,
            'federalTaxNumber' => (int) $document,
            'type' => $isCompany ? 4 : 2,
        ];

        if (filled($client->email)) {
            $buyer['email'] = $client->email;
        }

        return ['buyer' => $buyer, 'reason' => null];
    }

    /**
     * @return array{buyer: null, reason: string}
     */
    private function omit(string $reason): array
    {
        return ['buyer' => null, 'reason' => $reason];
    }
}
