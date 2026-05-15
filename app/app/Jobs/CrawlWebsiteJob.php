<?php

namespace App\Jobs;

use App\Models\CrawlJob as CrawlJobModel;
use App\Models\OnboardingSession;
use App\Services\CloudflareCrawler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CrawlWebsiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('crawl');
    }

    public function handle(CloudflareCrawler $crawler): void
    {
        $session = OnboardingSession::findOrFail($this->onboardingSessionId);
        $session->setStep('crawl', 'in_progress');

        $job = CrawlJobModel::create([
            'onboarding_session_id' => $session->id,
            'url'    => $session->website_url,
            'status' => 'running',
            'limit'  => 25,
            'depth'  => 2,
        ]);

        $crawler->crawl($job);

        $session->setStep('crawl', 'completed', ['crawl_job_id' => $job->id]);
    }
}
