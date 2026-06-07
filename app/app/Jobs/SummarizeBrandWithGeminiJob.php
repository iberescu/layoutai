<?php

namespace App\Jobs;

use App\Models\AdConcept;
use App\Models\AdVariant;
use App\Models\BrandProfile;
use App\Models\Campaign;
use App\Models\CrawlPage;
use App\Models\OnboardingSession;
use App\Models\UploadedAsset;
use App\Services\BrandImageHarvester;
use App\Services\GeminiBrandAndAdsService;
use App\Services\ImageFocalService;
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

    // Single attempt — the GeminiClient already retries internally. Without
    // this Laravel would re-run the whole 5-min call up to 3 times on a
    // genuine Gemini failure (e.g. safety-filter timeout), making users
    // wait 15 minutes for a "failed" status.
    public int $tries   = 1;
    public int $timeout = 480; // 8 min — generous headroom for the 5-min HTTP call.

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('ai');
    }

    public function handle(GeminiBrandAndAdsService $service, NewsEventService $events, BrandImageHarvester $imageHarvester, ImageFocalService $focal): void
    {
        $session = OnboardingSession::findOrFail($this->onboardingSessionId);
        $session->setStep('summarize_brand', 'in_progress');
        $session->setStep('concepts', 'in_progress');

        // HARD STOP: refuse to invent a brand when the crawl returned nothing
        // useful. Threshold is 800 chars across all pages — anything below
        // that is realistically an error page / DNS-fail stub / interstitial
        // and Gemini cannot produce a grounded brand from it. We had users
        // complain about hallucinated "General consumer brand" stubs; this
        // surfaces a clear error instead.
        $totalChars = (int) CrawlPage::whereHas('crawlJob', fn ($q) => $q->where('onboarding_session_id', $session->id))
            ->selectRaw('COALESCE(SUM(LENGTH(markdown)), 0) AS total')
            ->value('total');
        if ($totalChars < 800) {
            $msg = "We couldn't read enough content from {$session->website_url}. "
                 . "Common causes: the site blocks bots, requires JavaScript we can't run, "
                 . "or returned an error page. Try the apex domain (without `www`), check the URL is spelled right, "
                 . "or try a different site.";
            $session->update(['status' => 'failed', 'error' => $msg]);
            $session->setStep('summarize_brand', 'failed', ['error' => $msg]);
            $session->setStep('concepts',        'failed', ['error' => $msg]);
            return;
        }

        // Harvest 5 usable images from the crawl to pass to Gemini — these
        // get embedded in up to 5 concepts as real_image_url, replacing the
        // runmyprint AI image for those tiles.
        $brandImages = $imageHarvester->harvestFor($session, 5);

        try {
            $payload = $service->generate($session, [], null, $brandImages);
        } catch (\App\Exceptions\GeminiQuotaExceededException $e) {
            // Project-level billing cap — needs admin action, not a user retry.
            $msg = "Our AI service has hit its monthly budget cap. We're working on it — please check back in a few hours, or contact support@layout.ai for an urgent generation.";
            $session->update(['status' => 'failed', 'error' => $msg]);
            $session->setStep('summarize_brand', 'failed', ['error' => 'quota_exceeded']);
            $session->setStep('concepts',        'failed', ['error' => 'quota_exceeded']);
            return;
        } catch (\Throwable $e) {
            // Discriminate between a timeout (Gemini was slow) and a genuine
            // refusal (safety filter / empty payload). The user can act on
            // both differently — a timeout = retry; safety-filter = different
            // URL or different angle.
            $detail   = $e->getMessage();
            $isTimeout = str_contains($detail, 'timed out') || str_contains($detail, 'cURL error 28');
            $msg = $isTimeout
                ? "Our AI took too long to analyse {$session->website_url}. This usually means the brand has unusual content (heavy non-English text, regulated industries, or political topics). Please try again — a second attempt often succeeds."
                : "Our AI couldn't generate a brand profile for {$session->website_url}. The site may have content that our safety filters can't process. Try a more product-focused URL or a different page on the same site.";
            $session->update(['status' => 'failed', 'error' => $msg]);
            $session->setStep('summarize_brand', 'failed', ['error' => $detail]);
            $session->setStep('concepts',        'failed', ['error' => $detail]);
            return;
        }

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
                'style'       => $c['style']        ?? 'standard',
                'platform'    => $c['platform']     ?? 'display',
                'source_type' => ($c['ad_type'] ?? 'brand') === 'event' ? 'event' : 'brand',
                'status'      => 'generated',
                'meta'        => [
                    'image_prompt'    => $c['image_prompt']    ?? null,
                    'real_image_url'  => $c['real_image_url']  ?? null,
                    'primary_color'   => $brand->primaryColor(),
                    'accent_color'    => $brand->accentColor(),
                    'news_event'      => $c['news_event']      ?? null,
                    'position'        => $c['position']        ?? 'bottom',
                    'font_sizes'      => $c['font_sizes']      ?? null,
                    'animation_hint'  => $c['animation_hint']  ?? null,
                ],
            ]);

            // If Gemini assigned a real brand image to this concept, attach
            // it directly as the AdImage so the runmyprint fetch is skipped.
            // Compute the focal point via smartcrop so the HTML pipeline can
            // crop the image without cutting off the subject.
            if (! empty($c['real_image_url']) && filter_var($c['real_image_url'], FILTER_VALIDATE_URL)) {
                $focalPoint = $focal->focalFor($c['real_image_url']);
                $variant->image()->create([
                    'prompt'      => $c['image_prompt'] ?? '',
                    'prompt_hash' => hash('sha256', 'real:' . $c['real_image_url']),
                    'source_url'  => $c['real_image_url'],
                    'stored_url'  => $c['real_image_url'],
                    'focal_x'     => $focalPoint['x'] ?? null,
                    'focal_y'     => $focalPoint['y'] ?? null,
                    'status'      => 'reused',
                ]);
            }
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
