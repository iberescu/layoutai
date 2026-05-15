<?php

namespace App\Services;

use App\Models\BrandProfile;
use App\Models\CrawlPage;
use App\Models\OnboardingSession;
use Illuminate\Support\Str;

class GeminiBrandService
{
    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    public function summarize(OnboardingSession $session): BrandProfile
    {
        $pages = CrawlPage::whereHas('crawlJob', fn ($q) => $q->where('onboarding_session_id', $session->id))
            ->limit(15)->get();

        $context = $pages->map(fn ($p) => "## {$p->url}\n{$p->title}\n\n" . Str::limit($p->markdown ?? '', 2000))
            ->implode("\n\n---\n\n");

        $prompt = <<<PROMPT
You are extracting a structured brand profile for an AD GENERATION SYSTEM.
Return STRICT JSON matching the provided schema. No prose.

Website: {$session->website_url}
Location: {$session->business_location}
Goal: {$session->campaign_goal}

CONTENT FROM CRAWL:
{$context}

Output JSON keys: company_name, industry, business_model, short_description,
target_audiences (array), main_products (array), value_propositions (array),
proof_points (array), brand_voice (object with tone, personality (array),
words_to_use (array), words_to_avoid (array)), visual_identity (object with
primary_color, secondary_color, accent_color, logo_usage_notes),
ad_compliance_risks (array), recommended_ctas (array).
PROMPT;

        $schema = [
            'type' => 'object',
            'properties' => [
                'company_name'        => ['type' => 'string'],
                'industry'            => ['type' => 'string'],
                'business_model'      => ['type' => 'string'],
                'short_description'   => ['type' => 'string'],
                'target_audiences'    => ['type' => 'array', 'items' => ['type' => 'string']],
                'main_products'       => ['type' => 'array', 'items' => ['type' => 'string']],
                'value_propositions'  => ['type' => 'array', 'items' => ['type' => 'string']],
                'proof_points'        => ['type' => 'array', 'items' => ['type' => 'string']],
                'recommended_ctas'    => ['type' => 'array', 'items' => ['type' => 'string']],
                'ad_compliance_risks' => ['type' => 'array', 'items' => ['type' => 'string']],
                'brand_voice'         => [
                    'type'       => 'object',
                    'properties' => [
                        'tone'           => ['type' => 'string'],
                        'personality'    => ['type' => 'array', 'items' => ['type' => 'string']],
                        'words_to_use'   => ['type' => 'array', 'items' => ['type' => 'string']],
                        'words_to_avoid' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'visual_identity'     => [
                    'type'       => 'object',
                    'properties' => [
                        'primary_color'    => ['type' => 'string'],
                        'secondary_color'  => ['type' => 'string'],
                        'accent_color'     => ['type' => 'string'],
                        'logo_usage_notes' => ['type' => 'string'],
                    ],
                ],
            ],
            'required' => ['company_name', 'industry'],
        ];

        $payload = $this->gemini->generateJson($prompt, $schema) ?: $this->stub($session, $pages);

        $brand = BrandProfile::create([
            'workspace_id'           => $session->workspace_id,
            'onboarding_session_id'  => $session->id,
            'website_url'            => $session->website_url,
            'company_name'           => $payload['company_name']        ?? null,
            'industry'               => $payload['industry']            ?? null,
            'description'            => $payload['short_description']   ?? null,
            'target_audience_json'   => $payload['target_audiences']    ?? [],
            'brand_voice_json'       => $payload['brand_voice']         ?? [],
            'colors_json'            => $payload['colors']              ?? [],
            'visual_identity_json'   => $payload['visual_identity']     ?? [],
            'proof_points_json'      => $payload['proof_points']        ?? [],
            'ctas_json'              => $payload['recommended_ctas']    ?? [],
            'compliance_risks_json'  => $payload['ad_compliance_risks'] ?? [],
        ]);

        $session->update(['brand_profile_id' => $brand->id]);

        return $brand;
    }

    private function stub(OnboardingSession $session, $pages): array
    {
        $host = parse_url($session->website_url, PHP_URL_HOST) ?: 'example.com';
        $name = ucwords(str_replace(['.', '-', '_'], ' ', preg_replace('/^www\./', '', $host)));

        return [
            'company_name'      => $name,
            'industry'          => 'General consumer brand',
            'business_model'    => 'Direct-to-consumer',
            'short_description' => "$name provides modern, friendly products with reliable local delivery.",
            'target_audiences'  => ['Busy professionals', 'Young families', 'Local customers'],
            'main_products'     => ['Signature product line', 'Subscription'],
            'value_propositions'=> ['Quality you can trust', 'Fast local delivery', 'Friendly support'],
            'proof_points'      => ['Trusted by thousands', '4.8 star average rating'],
            'brand_voice'       => [
                'tone' => 'Friendly, confident, modern',
                'personality'    => ['warm', 'helpful', 'premium'],
                'words_to_use'   => ['fresh', 'crafted', 'simple', 'trusted'],
                'words_to_avoid' => ['cheap', 'best ever', 'miracle'],
            ],
            'visual_identity' => [
                'primary_color'    => '#2563EB',
                'secondary_color'  => '#0F172A',
                'accent_color'     => '#7C3AED',
                'logo_usage_notes' => 'Keep clear space around the mark; never recolor.',
            ],
            'ad_compliance_risks' => ['Avoid unsupported claims', 'Avoid sensational language'],
            'recommended_ctas'    => ['Shop now', 'Try it free', 'Learn more', 'Order today'],
        ];
    }
}
