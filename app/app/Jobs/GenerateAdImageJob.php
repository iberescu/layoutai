<?php

namespace App\Jobs;

use App\Models\AdVariant;
use App\Services\AdImageGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Per-variant image-generation job. Dispatched in parallel for every
 * variant; works run in parallel across workers/threads.
 */
class GenerateAdImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 3;

    public function __construct(public int $variantId)
    {
        $this->onQueue('image');
    }

    public function handle(AdImageGenerationService $images): void
    {
        $variant = AdVariant::with('image')->find($this->variantId);
        if (! $variant) {
            return;
        }
        if (! $variant->image) {
            $prompt = $variant->meta['image_prompt']
                ?? 'modern editorial product photograph, premium commercial style, no text, no logo, no watermark';
            $images->generateForVariant($variant, $prompt);
        }

        // Fire the single-variant HTML build the moment the image is ready,
        // instead of waiting for the batched build job to poll on a 3s tick.
        // Shaves 5-20s off full completion. (The batch job in GenerateAdImagesJob
        // is still there as a safety net.) Skip if HTML already exists.
        if (! $variant->fresh()->html) {
            BuildAdHtmlJob::dispatch($this->variantId);
        }
    }
}
