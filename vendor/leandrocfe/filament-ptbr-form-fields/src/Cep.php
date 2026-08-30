<?php

namespace Leandrocfe\FilamentPtbrFormFields;

use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Leandrocfe\FilamentPtbrFormFields\Concerns\HasCepModes;
use Leandrocfe\FilamentPtbrFormFields\Providers\CepProviderInterface;
use Leandrocfe\FilamentPtbrFormFields\Providers\ViaCepProvider;

/**
 * Brazilian ZIP code (CEP) field with automatic address lookup.
 */
class Cep extends TextInput
{
    use HasCepModes;

    protected null|string|Htmlable $errorMessage = 'CEP inválido';

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->minLength(9)
            ->mask('99999-999')
            ->placeholder('00000-000');
    }

    public function errorMessage(null|string|Htmlable $message): static
    {
        $this->errorMessage = $message;

        return $this;
    }

    public function getErrorMessage(): null|string|Htmlable
    {
        return $this->errorMessage;
    }

    /**
     * Configure the CEP provider and callback.
     */
    public function api(string|CepProviderInterface $provider, callable $callback): static
    {
        $providerInstance = $this->resolveProvider($provider);

        $this->configureFieldMode($providerInstance, $callback);

        return $this;
    }

    private function resolveProvider(string|CepProviderInterface $provider): CepProviderInterface
    {
        $providerInstance = is_string($provider) ? new $provider : $provider;

        if (! $providerInstance instanceof CepProviderInterface) {
            throw new InvalidArgumentException(
                'The provider must implement the CepProviderInterface interface.'
            );
        }

        return $providerInstance;
    }

    /**
     * Legacy method for backward compatibility.
     *
     * @deprecated Use api() method instead.
     */
    public function viaCep(string $mode = 'suffix', string $errorMessage = 'CEP inválido.', array $setFields = []): static
    {
        return $this->api(
            provider: ViaCepProvider::class,
            callback: function ($set, $response) use ($setFields) {
                foreach ($setFields as $key => $value) {
                    $set($key, $response[$value] ?? null);
                }
            },
        );
    }
}
