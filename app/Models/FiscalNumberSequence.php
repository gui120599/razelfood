<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sequência de numeração fiscal. PROPOSITALMENTE sem escopo de tenant: a
 * unicidade é por (CNPJ do emitente, ambiente, modelo, série) entre tenants
 * (MT-05) — `tenant_id` é só o tenant de origem. Acessar só via ReserveFiscalNumber.
 */
class FiscalNumberSequence extends Model
{
    protected $fillable = [
        'tenant_id',
        'issuer_cnpj',
        'environment',
        'model',
        'series',
        'last_number',
    ];

    protected function casts(): array
    {
        return [
            'model' => 'integer',
            'series' => 'integer',
            'last_number' => 'integer',
        ];
    }
}
