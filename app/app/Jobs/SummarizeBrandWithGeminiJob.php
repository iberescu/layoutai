<?php

namespace App\Jobs;

use App\Models\AdConcept;
use App\Models\AdVariant;
use App\Models\BrandProfile;
use App\Models\Campaign;
use App\Models\OnboardingSession;
use App\Models\UploadedAsset;
use App\Services\GeminiBrandAndAdsService;
use App\Services\NewsEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Single-step that combines brand summary + 30 ad concepts in ONE Gemini call.
 * Replaces the old SummarizeBrand + GenerateAdConcepts pair.
 */
class SummarizeBrandWithGeminiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('ai');
    }

    public function handle(GeminiBrandAndAdsService $service, NewsEventService $events): void
    {
        $session = OnboardingSession::findOrFail($this->onboardingSessionId);
        $session->setStep('summarize_brand', 'in_progress');
        $session->setStep('concepts', 'in_progress');

        $eventList = $events->eligibleFor($session->business_location ?? '');
        $payload   = $service->generate($session, $eventList, 30);

        $brand = $this->persistBrand($session, $payload['brand']);
        $session->setStep('summarize_brand', 'completed', ['brand_profile_id' => $brand->id]);

        $campaign = Campaign::create([
            'workspace_id'     => $session->workspace_id,
            'brand_profile_id' => $brand->id,
            'name'             => 'Preview generation',
            'status'           => 'draft',
            'goal'             => $session->campaign_goal ?: 'awareness',
        ]);

        foreach ($payload['concepts'] as $c) {
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
                    'news_event'    => $c['news_event']    ?? null,
                    'position'      => $c['position']      ?? 'bottom',
                    'font_sizes'    => $c['font_sizes']    ?? null,
                ],
            ]);
        }

        $session->setStep('concepts', 'completed', [
            'campaign_id' => $campaign->id,
            'count'       => count($payload['concepts']),
        ]);
        // Variants exist now — flip session to streaming so /processing page
        // can redirect to /preview and watch renders land in real time.
        $session->update(['status' => 'preview_streaming']);
    }

    private function persistBrand(OnboardingSession $session, array $payload): BrandProfile
    {
        // If the user uploaded a logo, the client-side extractor sent us the
        // canonical palette. Override Gemini's color choices with those so
        // the generated ads can't clash with the actual logo.
        $logoColors = $session->logo_colors_json ?: [];
        $visualIdentity = $payload['visual_identity'] ?? [];
        $colorsJson     = $payload['colors']           ?? [];
        if (! empty($logoColors)) {
            $visualIdentity['primary_color']   = $logoColors[0];
            $visualIdentity['accent_color']    = $logoColors[1] ?? $logoColors[0];
            $visualIdentity['secondary_color'] = $logoColors[2] ?? ($logoColors[1] ?? $logoColors[0]);
            $colorsJson = [
                'primary'   => $logoColors[0],
                'accent'    => $logoColors[1] ?? $logoColors[0],
                'secondary' => $logoColors[2] ?? ($logoColors[1] ?? $logoColors[0]),
                'palette'   => $logoColors,
                'source'    => 'logo',
            ];
        }

        $brand = BrandProfile::create([
            'workspace_id'           => $session->workspace_id,
            'onboarding_session_id'  => $session->id,
            'website_url'            => $session->website_url,
            'company_name'           => $payload['company_name']        ?? null,
            'industry'               => $payload['industry']            ?? null,
            'description'            => $payload['short_description']   ?? null,
            'target_audience_json'   => $payload['target_audiences']    ?? [],
            'brand_voice_json'       => $payload['brand_voice']         ?? [],
            'colors_json'            => $colorsJson,
            'visual_identity_json'   => $visualIdentity,
            'proof_points_json'      => $payload['proof_points']        ?? [],
            'ctas_json'              => $payload['recommended_ctas']    ?? [],
            'compliance_risks_json'  => $payload['ad_compliance_risks'] ?? [],
        ]);

        $session->update(['brand_profile_id' => $brand->id]);

        if ($session->logo_path) {
            $asset = UploadedAsset::where('onboarding_session_id', $session->id)
                ->where('type', 'logo')->latest()->first();
            if ($asset) {
                $brand->update(['logo_asset_id' => $asset->id]);
            }
        }

        return $brand;
    }
}
