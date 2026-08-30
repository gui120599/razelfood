<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Armazenamento genérico de preferências por usuário (ex.: filtros
 * lembrados em telas com filtro). `key` é livre — uma preferência nova não
 * exige migration nova, só uma chave nova (ex.: 'kitchen.filters',
 * 'orders.table_filters').
 */
class UserPreference extends TenantScopedModel
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public static function valueFor(User $user, string $key, array $default = []): array
    {
        return static::query()->where('user_id', $user->id)->where('key', $key)->first()?->value ?? $default;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function rememberFor(User $user, string $key, array $value): void
    {
        static::query()->updateOrCreate(
            ['user_id' => $user->id, 'key' => $key],
            ['value' => $value],
        );
    }
}
