<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdRender extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ad_variant_id', 'format', 'asset_url', 'file_size_bytes',
        'width', 'height', 'render_status', 'validation_errors_json',
        'created_at',
    ];

    protected $casts = [
        'validation_errors_json' => 'array',
        'created_at'             => 'datetime',
    ];

    public function adVariant(): BelongsTo
    {
        return $this->belongsTo(AdVariant::class);
    }
}
