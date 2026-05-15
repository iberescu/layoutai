<?php

namespace App\Jobs;

use App\Models\OnboardingSession;
use App\Services\GeminiBrandService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SummarizeBrandWithGeminiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('ai');
    }

    public function handle(GeminiBrandService $brandService): void
    {
        $session = OnboardingSession::findOrFail($this->onboardingSessionId);
        $session->setStep('summarize_brand', 'in_progress');

        // Attach uploaded logo to the brand profile if present
        $brand = $brandService->summarize($session);
        if ($session->logo_path) {
            $asset = \App\Models\UploadedAsset::where('onboarding_session_id', $session->id)
                ->where('type', 'logo')->latest()->first();
            if ($asset) {
                $brand->update(['logo_asset_id' => $asset->id]);
            }
        }

        $session->setStep('summarize_brand', 'completed', ['brand_profile_id' => $brand->id]);
    }
}
