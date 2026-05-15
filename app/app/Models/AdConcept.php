<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdConcept extends Model
{
    protected $fillable = ['campaign_id', 'concept', 'ad_type', 'strategy_json'];

    protected $casts = [
        'strategy_json' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(AdVariant::class, 'concept_id');
    }
}
