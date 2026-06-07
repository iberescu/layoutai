<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdImage extends Model
{
    protected $fillable = [
        'ad_variant_id', 'prompt', 'prompt_hash', 'source_url', 'stored_url',
        'focal_x', 'focal_y',
        'status', 'error_message', 'width', 'height', 'file_size_bytes',
    ];

    protected $casts = [
        'focal_x' => 'float',
        'focal_y' => 'float',
    ];

    public function adVariant(): BelongsTo
    {
        return $this->belongsTo(AdVariant::class);
    }
}
