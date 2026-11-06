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
        $preferred = preg_replace('/\D/', '', (string) ($isCompany ? $client->cnpj : $client->cpf));
        $found = $this->validDocument($client, $isCompany);

        if ($found === null) {
            return $this->omit($preferred === ''
                ? 'Cliente sem '.($isCompany ? 'CNPJ' : 'CPF').' cadastrado: consumidor não identificado.'
                : 'Cliente com '.($isCompany ? 'CNPJ' : 'CPF').' inválido: consumidor não identificado.');
        }

        [$isCnpj, $document] = $found;

        $buyer = [
            'stateTaxNumberIndicator' => 'NonTaxPayer',
            'tradeName' => $client->name,
            'taxRegime' => 'isento',
            'stateTaxNumber' => filled($client->state_registration) ? $client->state_registration : null,
            'id' => (string) $client->id,
            'name' => $client->name,
            // Texto, não número: CPF com zero à esquerda perderia o zero como inteiro no JSON.
            'federalTaxNumber' => $document,
            'email' => filter_var($client->email, FILTER_VALIDATE_EMAIL) ? $client->email : null,
            'type' => $isCnpj ? 4 : 2,
        ];

        return ['buyer' => array_filter($buyer, fn (mixed $value): bool => $value !== null), 'reason' => null];
    }

    /**
     * Documento válido a enviar: o do tipo do cliente; se ele não tiver, o outro, quando válido
     * (mesmo comportamento do Pizzaria-App em produção).
     *
     * @return array{0: bool, 1: string}|null [é CNPJ, só dígitos]
     */
    private function validDocument(Client $client, bool $isCompany): ?array
    {
        $candidates = $isCompany
            ? [[true, $client->cnpj], [false, $client->cpf]]
            : [[false, $client->cpf], [true, $client->cnpj]];

        foreach ($candidates as [$isCnpj, $value]) {
            $digits = preg_replace('/\D/', '', (string) $value);

            if ($digits === '') {
                continue;
            }

            $rule = $isCnpj ? new ValidCnpj : new ValidCpf;

            if (! Validator::make(['document' => $digits], ['document' => [$rule]])->fails()) {
                return [$isCnpj, $digits];
            }
        }

        return null;
    }

    /**
     * @return array{buyer: null, reason: string}
     */
    private function omit(string $reason): array
    {
        return ['buyer' => null, 'reason' => $reason];
    }
}
