<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class State extends Model
{
    protected $fillable = [
        'name',
        'uf',
        'ibge_code',
    ];

    protected function casts(): array
    {
        return [
            'ibge_code' => 'integer',
        ];
    }

    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    public function locationSyncs(): HasMany
    {
        return $this->hasMany(LocationSync::class);
    }
}
