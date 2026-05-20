<?php

namespace App\Jobs;

use App\Models\AdVariant;
use App\Services\CreativeScoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scores one variant. Idempotent — bails if the variant already has a
 * creative_score. Triggered after HTML is built; also re-dispatchable from
 * the cron top-up so newly-generated variants get scored too.
 */
class ScoreAdVariantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Job timeout must outlast the scoring service's 600s poll deadline +
    // some margin for the synchronous HTTP roundtrips on top.
    public int $timeout = 720;
    public int $tries   = 2;

    public function __construct(public int $variantId)
    {
        $this->onQueue('ai');
    }

    public function handle(CreativeScoringService $scorer): void
    {
        $variant = AdVariant::with('image')->find($this->variantId);
        if (! $variant || $variant->creative_score !== null) {
            return;
        }

        $result = $scorer->score($variant);
        if ($result === null) {
            return; // logged inside the service; another pass can retry
        }

        $variant->update([
            'creative_score'      => $result['score'],
            'creative_score_meta' => $result['meta'] ?? null,
            'creative_scored_at'  => now(),
        ]);
    }
}
