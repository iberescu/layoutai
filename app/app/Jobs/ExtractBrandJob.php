<?php

namespace App\Jobs;

use App\Models\OnboardingSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExtractBrandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $session = OnboardingSession::findOrFail($this->onboardingSessionId);
        $session->setStep('extract_brand', 'in_progress');

        // Aggregate raw signals from crawl_pages -> simple extraction (images, og:image, etc).
        // Real extraction would use HTML parsing and dominant-color analysis; the brand
        // summarization job below handles narrative + colors. This step prepares context.
        $pages = $session->latestCrawlJob?->pages ?? collect();
        $images = $pages->pluck('images')->flatten(1)->filter()->take(50)->values()->all();

        $session->setStep('extract_brand', 'completed', ['image_count' => count($images)]);
    }
}
