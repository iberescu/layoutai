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

        // If a previous step already marked the session as failed (e.g.
        // SummarizeBrandWithGeminiJob caught a Gemini failure), do nothing.
        // Without this guard the finalizer would blindly flip status back to
        // preview_ready, hiding the real error from the user.
        if ($session->status === 'failed' || $session->brand_profile_id === null) {
            $session->setStep('templates', 'skipped', ['reason' => 'session_already_failed']);
            return;
        }

        $session->setStep('templates', 'in_progress');

        $brandId       = $session->brand_profile_id;
        $deadline      = time() + 420;
        $lastNudgeAt   = 0;

        while (time() < $deadline) {
            $total = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $brandId))->count();
            $done  = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $brandId))
                ->whereNotNull('html')
                ->count();
            if ($total > 0 && $done >= $total) {
                break;
            }
            // Every 20s, nudge any variant that has an image but no html (or
            // is a showcase with no image needed). The event-driven dispatch
            // from GenerateAdImageJob occasionally misses a variant due to a
            // race with the batched HTML job; this is the safety net so the
            // session always reaches preview_ready.
            if (time() - $lastNudgeAt >= 20) {
                $lastNudgeAt = time();
                $stalled = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $brandId))
                    ->whereNull('html')
                    ->where(function ($q) {
                        $q->where('style', 'showcase')
                          ->orWhereHas('image');
                    })
                    ->pluck('id');
                foreach ($stalled as $id) {
                    BuildAdHtmlJob::dispatch($id);
                }
            }
            sleep(2);
        }

        $finalDone = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $brandId))
            ->whereNotNull('html')
            ->count();

        $session->setStep('templates', 'completed', ['count' => $finalDone]);
        // Re-read in case another job set status=failed while we were polling.
        if ($session->fresh()->status !== 'failed') {
            $session->update(['status' => 'preview_ready']);
        }

        // Sweep scoring as one final async batch — runs AFTER the user sees
        // preview_ready, so it doesn't compete with the HTML build for Gemini
        // bandwidth. Group by campaign (so the rubric is brand-correct) and
        // chunk to 8 per Gemini call.
        $unscored = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $brandId))
            ->whereNotNull('html')
            ->whereNull('creative_score')
            ->get(['id', 'campaign_id']);

        foreach ($unscored->groupBy('campaign_id') as $rows) {
            foreach ($rows->pluck('id')->chunk(8) as $chunk) {
                ScoreAdVariantsBatchJob::dispatch($chunk->values()->all())
                    ->onQueue('reporting'); // low-priority lane, no contention with future ad builds
            }
        }
    }
}
