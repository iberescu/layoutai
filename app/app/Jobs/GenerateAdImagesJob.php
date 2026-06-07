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
 * Fans out per-variant GenerateAdImageJob. Each variant's image is
 * fetched from runmyprint in parallel by workers.
 */
class GenerateAdImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 2;

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $session = OnboardingSession::findOrFail($this->onboardingSessionId);
        $session->setStep('images', 'in_progress');

        $variants = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $session->brand_profile_id))
            ->whereDoesntHave('image')
            ->get(['id', 'style']);

        // Showcase ads render entirely from inline SVG + CSS — no photo
        // needed. Skip the runmyprint fetch for them so the HTML batch
        // doesn't wait on an image that never arrives.
        foreach ($variants->where('style', '!=', 'showcase')->pluck('id') as $id) {
            GenerateAdImageJob::dispatch($id);
        }

        // Chunk HTML batches BY STYLE so each Gemini call gets a homogeneous
        // cohort (standard / creative / animated / social) and the style-
        // specific directives in the prompt apply cleanly. Each batch waits
        // ~6s and re-queues until its images are ready.
        $batches = $variants
            ->groupBy(fn (AdVariant $v) => $v->style ?: 'standard')
            ->flatMap(fn ($group) => $group->pluck('id')->chunk(5));

        foreach ($batches as $chunk) {
            BuildAdHtmlBatchJob::dispatch($chunk->values()->all())
                ->delay(now()->addSeconds(6));
        }

        $session->setStep('images', 'completed', ['count' => $variants->count()]);
    }
}
