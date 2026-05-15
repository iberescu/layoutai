<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductFeed extends Model
{
    protected $fillable = [
        'workspace_id', 'source', 'url', 'status', 'last_synced_at', 'settings',
    ];

    protected $casts = [
        'settings'       => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductFeedItem::class);
    }
}
