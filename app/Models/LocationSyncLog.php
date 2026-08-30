<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationSyncLog extends Model
{
    protected $fillable = [
        'location_sync_id',
        'cep',
        'type',
        'message',
    ];

    public function locationSync(): BelongsTo
    {
        return $this->belongsTo(LocationSync::class);
    }
}
