<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsEventHook extends Model
{
    protected $fillable = [
        'title', 'type', 'source', 'external_id', 'location', 'date', 'expires_at',
        'relevance_score', 'risk_score',
        'recommended_angle', 'avoid', 'meta',
    ];

    protected $casts = [
        'date'       => 'date',
        'expires_at' => 'datetime',
        'avoid'      => 'array',
        'meta'       => 'array',
    ];
}
