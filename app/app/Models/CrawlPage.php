<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlPage extends Model
{
    protected $fillable = [
        'crawl_job_id', 'url', 'title', 'markdown', 'meta', 'images', 'links',
    ];

    protected $casts = [
        'meta'   => 'array',
        'images' => 'array',
        'links'  => 'array',
    ];

    public function crawlJob(): BelongsTo
    {
        return $this->belongsTo(CrawlJob::class);
    }
}
