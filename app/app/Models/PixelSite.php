<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PixelSite extends Model
{
    protected $fillable = [
        'workspace_id', 'site_id', 'domain', 'status', 'last_event_at',
    ];

    protected $casts = [
        'last_event_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $site) {
            if (empty($site->site_id)) {
                $site->site_id = Str::lower(Str::random(16));
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PixelEvent::class);
    }
}
