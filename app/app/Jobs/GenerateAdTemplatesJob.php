<?php

namespace App\Jobs;

use App\Models\AdVariant;
use App\Models\OnboardingSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Finalizer: polls until every variant in the brand's campaigns has its
 * AI-authored HTML populated (by BuildAdHtmlBatchJob / BuildAdHtmlJob),
 * then flips the session to preview_ready. The frontend serves the HTML
 * directly via <iframe srcdoc> — no PNG rasterization on the critical
 * path. PNG export remains available via RenderAdJob for ad-network
 * delivery later.
 */
class GenerateAdTemplatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 480;
    public int $tries   = 1;

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $session = OnboardingSession::findOrFail($this->onboardingSessionId);
        $session->setStep('templates', 'in_progress');

        $brandId  = $session->brand_profile_id;
        $deadline = time() + 420;

        while (time() < $deadline) {
            $total = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $brandId))->count();
            $done  = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $brandId))
                ->whereNotNull('html')
                ->count();
            if ($total > 0 && $done >= $total) {
                break;
            }
            sleep(2);
        }

        $finalDone = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $brandId))
            ->whereNotNull('html')
            ->count();

        $session->setStep('templates', 'completed', ['count' => $finalDone]);
        $session->update(['status' => 'preview_ready']);
    }
}
