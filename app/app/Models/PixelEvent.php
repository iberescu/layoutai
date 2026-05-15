<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PixelEvent extends Model
{
    protected $fillable = [
        'pixel_site_id', 'event_type', 'payload', 'referrer',
        'user_agent', 'ip_address', 'occurred_at',
    ];

    protected $casts = [
        'payload'     => 'array',
        'occurred_at' => 'datetime',
    ];

    public function pixelSite(): BelongsTo
    {
        return $this->belongsTo(PixelSite::class);
    }
}
