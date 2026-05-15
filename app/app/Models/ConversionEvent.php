<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversionEvent extends Model
{
    protected $fillable = [
        'pixel_site_id', 'ad_variant_id', 'type', 'value_cents',
        'currency', 'meta', 'occurred_at',
    ];

    protected $casts = [
        'meta'        => 'array',
        'occurred_at' => 'datetime',
    ];

    public function pixelSite(): BelongsTo
    {
        return $this->belongsTo(PixelSite::class);
    }

    public function adVariant(): BelongsTo
    {
        return $this->belongsTo(AdVariant::class);
    }
}
