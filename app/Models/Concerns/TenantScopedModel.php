<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Base para todo model de domínio isolado por tenant. Estender esta classe
 * em vez de Model diretamente reduz o risco de esquecer a trait
 * BelongsToTenant ao criar um model novo (RNF-01).
 */
abstract class TenantScopedModel extends Model
{
    use BelongsToTenant;
}
