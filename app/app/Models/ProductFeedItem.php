<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductFeedItem extends Model
{
    protected $fillable = [
        'product_feed_id', 'external_id', 'title', 'description',
        'image_url', 'product_url', 'price_cents', 'currency',
        'availability', 'raw',
    ];

    protected $casts = [
        'raw' => 'array',
    ];

    public function productFeed(): BelongsTo
    {
        return $this->belongsTo(ProductFeed::class);
    }
}
