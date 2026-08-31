<?php

namespace App\Actions\Orders;

use App\Models\Client;

/**
 * RN-01: busca cliente existente pelo telefone dentro do tenant; se achar,
 * reaproveita e atualiza nome/endereço com o que foi submetido agora (só os
 * campos preenchidos — em branco mantém o valor já cadastrado); se não
 * achar, cria um cadastro novo.
 */
class FindOrCreateClient
{
    /**
     * @param  array{zip_code?: ?string, street?: ?string, number?: ?string, complement?: ?string, neighborhood?: ?string, city?: ?string, state?: ?string}  $address
     * @param  ?string  $cpf  CPF do cliente (com ou sem máscara); só os dígitos são gravados. Em branco mantém o já cadastrado.
     */
    public function __invoke(string $phone, string $name, array $address = [], ?string $cpf = null): Client
    {
        $phone = $this->normalizePhone($phone);

        $client = Client::where('phone', $phone)->first();

        if ($client) {
            $client->update([
                'name' => $name,
                'cpf' => $this->normalizeCpf($cpf) ?? $client->cpf,
                'zip_code' => $address['zip_code'] ?? $client->zip_code,
                'street' => $address['street'] ?? $client->street,
                'number' => $address['number'] ?? $client->number,
                'complement' => $address['complement'] ?? $client->complement,
                'neighborhood' => $address['neighborhood'] ?? $client->neighborhood,
                'city' => $address['city'] ?? $client->city,
                'state' => $address['state'] ?? $client->state,
            ]);

            return $client;
        }

        return Client::create([
            'name' => $name,
            'phone' => $phone,
            'cpf' => $this->normalizeCpf($cpf),
            'zip_code' => $address['zip_code'] ?? null,
            'street' => $address['street'] ?? null,
            'number' => $address['number'] ?? null,
            'complement' => $address['complement'] ?? null,
            'neighborhood' => $address['neighborhood'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
        ]);
    }

    public function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone);
    }

    public function normalizeCpf(?string $cpf): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $cpf);

        return $digits === '' ? null : $digits;
    }
}
