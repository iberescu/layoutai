<?php

namespace App\Jobs;

use App\Models\AdVariant;
use App\Models\OnboardingSession;
use App\Services\AdTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateAdTemplatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('ai');
    }

    public function handle(AdTemplateService $templates): void
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
            ->get();

        foreach ($variants as $variant) {
            $imageUrl = $variant->image?->stored_url;
            $built    = $templates->buildHtml($variant, $brand, $imageUrl, $logoUrl);
            $variant->update([
                'html' => $built['html'],
                'css'  => $built['css'],
            ]);
        }

        $session->setStep('templates', 'completed', ['count' => $variants->count()]);
    }
}
