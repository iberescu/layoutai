<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AdVariant extends Model
{
    protected $fillable = [
        'campaign_id', 'concept_id', 'size_width', 'size_height',
        'headline', 'subheadline', 'body', 'cta', 'html', 'css',
        'layout_type', 'status', 'source_type', 'news_event_id',
        'policy_status', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(AdConcept::class, 'concept_id');
    }

    public function image(): HasOne
    {
        return $this->hasOne(AdImage::class);
    }

    public function renders(): HasMany
    {
        return $this->hasMany(AdRender::class);
    }

    public function newsEvent(): BelongsTo
    {
        return $this->belongsTo(NewsEventHook::class, 'news_event_id');
    }
}
