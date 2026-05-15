<?php

namespace App\Jobs;

use App\Models\AdConcept;
use App\Models\AdVariant;
use App\Models\Campaign;
use App\Models\OnboardingSession;
use App\Services\GeminiAdService;
use App\Services\NewsEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateAdConceptsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('ai');
    }

    public function handle(GeminiAdService $adService, NewsEventService $events): void
    {
        $session = OnboardingSession::findOrFail($this->onboardingSessionId);
        $session->setStep('concepts', 'in_progress');

        $brand = $session->brandProfile;
        if (! $brand) {
            $session->setStep('concepts', 'failed', ['error' => 'no brand profile']);
            return;
        }

        // Pre-signup, we don't have a workspace yet. Use a placeholder campaign and
        // reassign workspace_id on claim/register.
        $campaign = Campaign::create([
            'workspace_id'     => $session->workspace_id,
            'brand_profile_id' => $brand->id,
            'name'             => 'Preview generation',
            'status'           => 'draft',
            'goal'             => $session->campaign_goal ?: 'awareness',
        ]);

        $eventList = $events->eligibleFor($session->business_location ?? '');
        $concepts  = $adService->generateConcepts($campaign, $brand, 30, $eventList);

        foreach ($concepts as $c) {
            $concept = AdConcept::create([
                'campaign_id'   => $campaign->id,
                'concept'       => $c['concept'] ?? 'Concept',
                'ad_type'       => $c['ad_type'] ?? 'brand',
                'strategy_json' => $c,
            ]);

            AdVariant::create([
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
                    'primary_color' => $brand->primaryColor(),
                    'accent_color'  => $brand->accentColor(),
                    'news_event'    => $c['news_event'] ?? null,
                ],
            ]);
        }

        $session->setStep('concepts', 'completed', [
            'campaign_id' => $campaign->id,
            'count'       => count($concepts),
        ]);
    }
}
