<?php

namespace App\Jobs;

use App\Models\AdVariant;
use App\Models\OnboardingSession;
use App\Services\AdImageGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateAdImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 2;

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('image');
    }

    public function handle(AdImageGenerationService $images): void
    {
        $session = OnboardingSession::findOrFail($this->onboardingSessionId);
        $session->setStep('images', 'in_progress');

        $variants = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $session->brand_profile_id))
            ->whereDoesntHave('image')   // skip variants the previous attempt finished
            ->get();
        foreach ($variants as $variant) {
            $prompt = $variant->meta['image_prompt'] ?? 'modern editorial product photograph, premium commercial style, no text, no logo, no watermark';
            $images->generateForVariant($variant, $prompt);
        }

        $session->setStep('images', 'completed', ['count' => $variants->count()]);
    }
}
