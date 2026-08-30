<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FlavorQuantityOption extends TenantScopedModel
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'category_id',
        'label',
        'flavor_count',
        'flavor_shares',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'flavor_count' => 'integer',
            'flavor_shares' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Rateio igualitário (%, soma sempre 100) usado como valor inicial no
     * form e como fallback em tempo de checkout se `flavor_shares` estiver
     * ausente. O resto da divisão vai inteiro pro último sabor (RN: pizza
     * meio a meio confirmada pelo Admin com 33/33/34 para 3 sabores).
     *
     * @return array<int, float>
     */
    public static function equalShares(int $flavorCount): array
    {
        $count = max(1, $flavorCount);
        $base = round(100 / $count, 2);
        $shares = array_fill(0, $count - 1, $base);
        $shares[] = round(100 - array_sum($shares), 2);

        return $shares;
    }
}
