<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Linha de produção da cozinha (ex.: "Pizzas", "Hambúrgueres"), associada a
 * um conjunto de categorias — usada pra filtrar a Central de Pedidos por
 * pista (RF novo: cada estação só precisa ver pedidos com itens da sua
 * linha).
 */
class ProductionLine extends TenantScopedModel
{
    protected $fillable = [
        'tenant_id',
        'name',
    ];

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_production_line');
    }
}
