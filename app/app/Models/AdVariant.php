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
        'layout_type', 'style', 'platform', 'status', 'source_type',
        'news_event_id', 'policy_status', 'meta',
        'creative_score', 'creative_score_meta', 'creative_scored_at',
    ];

    protected $casts = [
        'meta'                => 'array',
        'creative_score'      => 'decimal:2',
        'creative_score_meta' => 'array',
        'creative_scored_at'  => 'datetime',
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
