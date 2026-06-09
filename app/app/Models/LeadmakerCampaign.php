<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Leadmaker (campaigns.leadmaker.ai) acquisition campaign provisioned for an
 * onboarded workspace. Inserted 'pending' at signup; the sync cron POSTs to
 * Leadmaker and fills in external_id + token, then the daily cron polls status.
 */
class LeadmakerCampaign extends Model
{
    protected $fillable = [
        'workspace_id', 'user_id', 'external_id', 'token', 'url', 'timezone',
        'customer_name', 'customer_email', 'customer_company', 'status',
        'request_payload', 'response_payload', 'last_status', 'last_synced_at',
        'attempts', 'error',
    ];

    protected $casts = [
        'request_payload'  => 'array',
        'response_payload' => 'array',
        'last_synced_at'   => 'datetime',
        'attempts'         => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(LeadmakerCampaignStatus::class);
    }
}
