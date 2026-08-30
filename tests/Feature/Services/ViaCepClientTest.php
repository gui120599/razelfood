<?php

namespace Tests\Feature\Services;

use App\Services\Address\ViaCepClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RN-33: a busca de CEP é sempre auxiliar — CEP inválido/não encontrado ou
 * falha do serviço externo devolvem null, nunca uma exceção que bloqueie o
 * checkout (a não ser que o chamador peça retry explícito).
 */
class ViaCepClientTest extends TestCase
{
    public function test_it_returns_the_structured_address_on_success(): void
    {
        Http::fake([
            'viacep.com.br/*' => Http::response([
                'logradouro' => 'Praça da Sé',
                'bairro' => 'Sé',
                'localidade' => 'São Paulo',
                'uf' => 'SP',
                'ibge' => '3550308',
            ]),
        ]);

        $address = app(ViaCepClient::class)->lookup('01001-000');

        $this->assertSame('Praça da Sé', $address['street']);
        $this->assertSame('Sé', $address['neighborhood']);
        $this->assertSame('São Paulo', $address['city']);
        $this->assertSame('SP', $address['state']);
    }

    public function test_it_returns_null_for_a_malformed_cep_without_calling_the_service(): void
    {
        Http::fake();

        $this->assertNull(app(ViaCepClient::class)->lookup('123'));

        Http::assertNothingSent();
    }

    public function test_it_returns_null_when_the_cep_is_not_found(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response(['erro' => true])]);

        $this->assertNull(app(ViaCepClient::class)->lookup('99999-999'));
    }

    public function test_it_returns_null_when_the_service_is_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->assertNull(app(ViaCepClient::class)->lookup('01001-000'));
    }
}
