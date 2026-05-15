<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\GeminiAdService;
use App\Services\NewsEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateDailyEventAdsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $campaignId)
    {
        $this->onQueue('ai');
    }

    public function handle(GeminiAdService $ads, NewsEventService $events): void
    {
        $campaign = Campaign::with('brandProfile', 'workspace')->findOrFail($this->campaignId);
        $brand    = $campaign->brandProfile;
        if (! $brand) {
            return;
        }

        $location = optional($campaign->workspace?->settings)['location'] ?? '';
        $eventList = $events->eligibleFor($location);

        $ads->generateConcepts($campaign, $brand, 5, $eventList);
    }
}
