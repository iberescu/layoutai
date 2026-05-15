<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $fillable = [
        'workspace_id', 'brand_profile_id', 'name', 'status', 'goal', 'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function brandProfile(): BelongsTo
    {
        return $this->belongsTo(BrandProfile::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(AdVariant::class);
    }

    public function concepts(): HasMany
    {
        return $this->hasMany(AdConcept::class);
    }
}
