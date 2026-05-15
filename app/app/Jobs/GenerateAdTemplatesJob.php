<?php

namespace App\Jobs;

use App\Models\AdVariant;
use App\Models\OnboardingSession;
use App\Services\GeminiHtmlAdService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateAdTemplatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Gemini Flash HTML calls take 5-10s each; 30 variants = several minutes.
    public int $timeout = 900;
    public int $tries   = 2;

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('ai');
    }

    public function handle(GeminiHtmlAdService $generator): void
    {
        $session = OnboardingSession::findOrFail($this->onboardingSessionId);
        $session->setStep('templates', 'in_progress');

        $brand   = $session->brandProfile;
        $logoUrl = $brand?->logoAsset?->url();

        if (! $brand) {
            $session->setStep('templates', 'failed', ['error' => 'no brand profile']);
            return;
        }

        $variants = AdVariant::with('image')
            ->whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $brand->id))
            ->whereNull('html')          // skip work the previous attempt finished
            ->get();

        foreach ($variants as $variant) {
            $imageUrl = $variant->image?->stored_url;
            $built    = $generator->buildHtml($variant, $brand, $imageUrl, $logoUrl);
            $variant->update([
                'html' => $built['html'],
                'css'  => $built['css'] ?? '',
            ]);
        }

        $total = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $brand->id))
            ->whereNotNull('html')->count();
        $session->setStep('templates', 'completed', ['count' => $total]);
    }
}
