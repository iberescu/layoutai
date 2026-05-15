<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsEventHook extends Model
{
    protected $fillable = [
        'title', 'type', 'location', 'date',
        'relevance_score', 'risk_score',
        'recommended_angle', 'avoid', 'meta',
    ];

    protected $casts = [
        'date'  => 'date',
        'avoid' => 'array',
        'meta'  => 'array',
    ];
}
