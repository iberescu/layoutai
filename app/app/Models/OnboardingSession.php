<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class OnboardingSession extends Model
{
    protected $fillable = [
        'uuid', 'website_url', 'logo_path', 'business_location',
        'campaign_goal', 'user_id', 'workspace_id', 'brand_profile_id',
        'status', 'steps', 'error',
    ];

    protected $casts = [
        'steps' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $session) {
            if (empty($session->uuid)) {
                $session->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function brandProfile(): BelongsTo
    {
        return $this->belongsTo(BrandProfile::class);
    }

    public function crawlJobs(): HasMany
    {
        return $this->hasMany(CrawlJob::class);
    }

    public function latestCrawlJob(): HasOne
    {
        return $this->hasOne(CrawlJob::class)->latestOfMany();
    }

    public function setStep(string $key, string $status, array $meta = []): void
    {
        $steps = $this->steps ?? [];
        $steps[$key] = array_merge(['status' => $status, 'updated_at' => now()->toIso8601String()], $meta);
        $this->steps = $steps;
        $this->save();
    }

    public function progressFraction(): float
    {
        $order = [
            'crawl', 'extract_brand', 'summarize_brand',
            'concepts', 'image_prompts', 'images',
            'templates', 'render',
        ];
        $done = 0;
        foreach ($order as $key) {
            $status = $this->steps[$key]['status'] ?? null;
            if ($status === 'completed') {
                $done++;
            }
        }
        return $done / count($order);
    }
}
