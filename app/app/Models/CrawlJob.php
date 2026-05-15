<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrawlJob extends Model
{
    protected $fillable = [
        'onboarding_session_id', 'url', 'status', 'limit', 'depth',
        'raw_response', 'error',
    ];

    protected $casts = [
        'raw_response' => 'array',
    ];

    public function onboardingSession(): BelongsTo
    {
        return $this->belongsTo(OnboardingSession::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(CrawlPage::class);
    }
}
