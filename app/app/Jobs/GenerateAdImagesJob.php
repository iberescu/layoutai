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

        $ids = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $session->brand_profile_id))
            ->whereDoesntHave('image')
            ->pluck('id');

        foreach ($ids as $id) {
            GenerateAdImageJob::dispatch($id);
        }

        $session->setStep('images', 'completed', ['count' => $ids->count()]);
    }
}
