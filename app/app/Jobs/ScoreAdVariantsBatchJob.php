<?php

namespace App\Jobs;

use App\Models\AdVariant;
use App\Services\GeminiScoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Scores N ad variants in a single Gemini 2.5 Flash call.
 * Dispatched per batch from BuildAdHtmlBatchJob right after HTML lands.
 */
class ScoreAdVariantsBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries   = 2;

    /** @param int[] $variantIds */
    public function __construct(public array $variantIds)
    {
        $this->onQueue('ai');
    }

    public function handle(GeminiScoringService $scorer): void
    {
        $variants = AdVariant::with('campaign.brandProfile', 'image')
            ->whereIn('id', $this->variantIds)
            ->whereNull('creative_score')
            ->get();

        if ($variants->isEmpty()) {
            return;
        }
        // All variants in a batch share one campaign / brand (the batch
        // is built by BuildAdHtmlBatchJob which is per-campaign).
        $results = $scorer->scoreBatch($variants);
        if (empty($results)) {
            return;
        }

        $now = Carbon::now();
        foreach ($variants as $variant) {
            $row = $results[$variant->id] ?? null;
            if (! $row) {
                continue;
            }
            $variant->update([
                'creative_score'      => $row['score'],
                'creative_score_meta' => [
                    'provider'  => 'gemini',
                    'rationale' => $row['rationale'],
                    'subscores' => $row['subscores'],
                ],
                'creative_scored_at'  => $now,
            ]);
        }
    }
}
