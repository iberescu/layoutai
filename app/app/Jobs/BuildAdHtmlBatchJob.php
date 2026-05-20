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
 * Builds HTML for up to N variants in a single Gemini call instead of
 * one call per variant. Variants in the batch whose image is not ready
 * yet are split off into a follow-up batch with a short delay so the
 * caller doesn't have to wait for ALL images before any HTML starts.
 */
class BuildAdHtmlBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries   = 2;

    /**
     * @param int[] $variantIds
     * @param int   $attempts how many times this batch has been re-queued waiting for images
     */
    public function __construct(public array $variantIds, public int $attempts = 0)
    {
        $this->onQueue('ai');
    }

    public function handle(GeminiHtmlAdService $generator): void
    {
        $variants = AdVariant::with('image', 'campaign.brandProfile.logoAsset')
            ->whereIn('id', $this->variantIds)
            ->whereNull('html')
            ->get();

        if ($variants->isEmpty()) {
            return;
        }

        $ready = $variants->filter(fn ($v) => $v->image?->stored_url);
        $waiting = $variants->reject(fn ($v) => $v->image?->stored_url);

        if ($ready->isEmpty()) {
            // No images yet; back off and try again.
            if ($this->attempts < 8) {
                static::dispatch($this->variantIds, $this->attempts + 1)->delay(now()->addSeconds(3));
            }
            return;
        }

        $brand = $ready->first()->campaign?->brandProfile;
        if (! $brand) {
            return;
        }
        $logoUrl = $brand->logoAsset?->url();

        $built = $generator->buildHtmlBatch($ready, $brand, $logoUrl);

        foreach ($ready as $variant) {
            if (! isset($built[$variant->id])) {
                // Batch dropped this one — fall back to single-variant HTML so it isn't lost.
                BuildAdHtmlJob::dispatch($variant->id);
                continue;
            }
            $variant->update([
                'html' => $built[$variant->id]['html'],
                'css'  => $built[$variant->id]['css'] ?? '',
            ]);
            // PNG render skipped on critical path — frontend uses the HTML
            // directly via <iframe srcdoc>. Re-enable when PNG export ships:
            // RenderAdJob::dispatch($variant->id);

            // TRIBE v2 creative scoring (hosted GPU). Staggered so the
            // remote endpoint doesn't get a thundering herd.
            ScoreAdVariantJob::dispatch($variant->id)
                ->delay(now()->addSeconds(2 + ($variant->id % 8)));
        }

        // Anything still waiting on its image goes into the next pass.
        if ($waiting->isNotEmpty() && $this->attempts < 8) {
            static::dispatch($waiting->pluck('id')->all(), $this->attempts + 1)
                ->delay(now()->addSeconds(3));
        }
    }
}
