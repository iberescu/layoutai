<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One daily snapshot of a Leadmaker campaign's status response
 * (GET /api/campaigns/{id}/status). Append-only time series.
 */
class LeadmakerCampaignStatus extends Model
{
    protected $fillable = [
        'leadmaker_campaign_id', 'external_id', 'status', 'payload', 'fetched_at',
    ];

    protected $casts = [
        'payload'    => 'array',
        'fetched_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(LeadmakerCampaign::class, 'leadmaker_campaign_id');
    }
}
