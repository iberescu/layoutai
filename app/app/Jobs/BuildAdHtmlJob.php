<?php

namespace App\Jobs;

use App\Models\AdVariant;
use App\Services\GeminiHtmlAdService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Per-variant HTML build via Gemini Flash. Dispatched 30+ at a time so
 * workers process them in parallel instead of serializing all Gemini
 * calls inside a single job.
 */
class BuildAdHtmlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    public function __construct(public int $variantId)
    {
        $this->onQueue('ai');
    }

    public function handle(GeminiHtmlAdService $generator): void
    {
        $variant = AdVariant::with('image', 'campaign.brandProfile.logoAsset')->find($this->variantId);
        if (! $variant || $variant->html) {
            return; // already built
        }

        $brand = $variant->campaign?->brandProfile;
        if (! $brand) {
            return;
        }
        $imageUrl = $variant->image?->stored_url;
        $logoUrl  = $brand->logoAsset?->url();

        $built = $generator->buildHtml($variant, $brand, $imageUrl, $logoUrl);
        $variant->update([
            'html' => $built['html'],
            'css'  => $built['css'] ?? '',
        ]);

        // PNG render + scoring both run later (the templates finalizer
        // sweeps unscored variants after preview_ready). Keeping this job
        // light makes the per-variant fallback path return in milliseconds.
    }
}
