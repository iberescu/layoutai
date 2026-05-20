<?php

namespace App\Jobs;

use App\Models\AdConcept;
use App\Models\AdVariant;
use App\Models\Campaign;
use App\Services\GeminiBrandAndAdsService;
use App\Services\NewsEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Generates more variants for a single campaign — up to $target total.
 * Reuses the same brand+ads Gemini call, then fans out into image
 * generation, HTML build, and creative scoring.
 *
 * Idempotent: if the campaign already has >= $target variants, returns
 * immediately. The hourly scheduler dispatches this per campaign.
 */
class TopUpCampaignAdsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 2;

    public function __construct(
        public int $campaignId,
        public int $target = 100,
    ) {
        $this->onQueue('ai');
    }

    public function handle(GeminiBrandAndAdsService $service, NewsEventService $events): void
    {
        // Per-campaign lock prevents the CLI command + hourly scheduler from
        // racing each other and over-shooting the target while the previous
        // Gemini call is still generating concepts. 10-min TTL covers the
        // worst-case generate-images-and-build-html tail.
        $lock = Cache::lock('top-up:campaign:' . $this->campaignId, 600);
        if (! $lock->get()) {
            Log::info("TopUpCampaignAdsJob: campaign={$this->campaignId} already locked, skipping");
            return;
        }

        try {
            $campaign = Campaign::with('brandProfile', 'workspace')->find($this->campaignId);
            if (! $campaign || ! $campaign->brandProfile) {
                return;
            }

            $existing = AdVariant::where('campaign_id', $campaign->id)->count();
            $need     = max(0, $this->target - $existing);
            if ($need === 0) {
                return;
            }
            // Cap the batch so a single hour doesn't try to do something crazy.
            $need = min($need, 70);

        Log::info("TopUpCampaignAdsJob: campaign={$campaign->id} existing={$existing} adding={$need}");

        $session   = $campaign->brandProfile->onboardingSession;
        $location  = $session?->business_location ?? '';
        $eventList = $events->eligibleFor($location);

        // Gemini call. The service generates a fresh brand summary too —
        // we discard it since the campaign already has one; only the
        // concepts portion matters here.
        $payload = $session
            ? $service->generate($session, $eventList, $need)
            : ['concepts' => []];

        $newVariantIds = [];
        foreach ($payload['concepts'] as $c) {
            $concept = AdConcept::create([
                'campaign_id'   => $campaign->id,
                'concept'       => $c['concept'] ?? 'Concept',
                'ad_type'       => $c['ad_type'] ?? 'brand',
                'strategy_json' => $c,
            ]);

            $variant = AdVariant::create([
                'campaign_id' => $campaign->id,
                'concept_id'  => $concept->id,
                'size_width'  => (int) ($c['size']['width']  ?? 300),
                'size_height' => (int) ($c['size']['height'] ?? 250),
                'headline'    => $c['headline']     ?? null,
                'subheadline' => $c['subheadline']  ?? null,
                'body'        => $c['body']         ?? null,
                'cta'         => $c['cta']          ?? null,
                'layout_type' => $c['layout_type']  ?? 'image-background-with-card-overlay',
                'source_type' => ($c['ad_type'] ?? 'brand') === 'event' ? 'event' : 'brand',
                'status'      => 'generated',
                'meta'        => [
                    'image_prompt'  => $c['image_prompt']  ?? null,
                    'primary_color' => $campaign->brandProfile->primaryColor(),
                    'accent_color'  => $campaign->brandProfile->accentColor(),
                    'news_event'    => $c['news_event']    ?? null,
                    'position'      => $c['position']      ?? 'bottom',
                    'font_sizes'    => $c['font_sizes']    ?? null,
                    'top_up_batch'  => true,
                ],
            ]);
            $newVariantIds[] = $variant->id;
        }

        if (empty($newVariantIds)) {
            return;
        }

            // Fan out image generation per variant.
            foreach ($newVariantIds as $id) {
                GenerateAdImageJob::dispatch($id);
            }
            // Batch-build HTML in chunks of 5 with a delay so images land first.
            foreach (array_chunk($newVariantIds, 5) as $chunk) {
                BuildAdHtmlBatchJob::dispatch($chunk)->delay(now()->addSeconds(8));
            }
        } finally {
            // Release the lock so the next hour's scheduler can run if needed.
            // (The lock auto-expires after 10 min, but we want to release it
            // proactively so an immediate re-run doesn't wait the full TTL.)
            optional($lock)->release();
        }
    }
}
