<?php

namespace App\Models;

use App\Enums\PersonType;
use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends TenantScopedModel
{
    protected $fillable = [
        'tenant_id',
        'name',
        'phone',
        'cpf',
        'address',
        'zip_code',
        'street',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'person_type',
        'cnpj',
        'state_registration',
        'email',
    ];

    protected static function booted(): void
    {
        // Invariante: pessoa física não guarda CNPJ/IE. Campo oculto no formulário não é
        // desidratado, então a limpeza não pode depender dele (troca de PJ para PF).
        static::saving(function (Client $client): void {
            if ($client->person_type === PersonType::Individual) {
                $client->cnpj = null;
                $client->state_registration = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'person_type' => PersonType::class,
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
