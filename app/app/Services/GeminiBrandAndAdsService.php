<?php

namespace App\Services;

use App\Models\CrawlPage;
use App\Models\OnboardingSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Combined Gemini call: brand summary + N ad concepts in ONE request.
 *
 * Replaces the two separate calls in GeminiBrandService and GeminiAdService.
 * Uses gemini-3.5-flash (configured at services.gemini.combined_model) so
 * the other GeminiHtmlAdService keeps its faster 2.5-flash setup.
 *
 * Each concept now also carries a position hint (top|middle|bottom) and an
 * explicit font_sizes block scaled to the ad's width/height — so downstream
 * HTML/CSS generation has a typography target instead of free-styling per ad.
 */
class GeminiBrandAndAdsService
{
    public const DEFAULT_SIZES = GeminiAdService::DEFAULT_SIZES;

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    /**
     * @return array{brand: array<string,mixed>, concepts: array<int,array<string,mixed>>}
     */
    public function generate(OnboardingSession $session, array $events = [], int $count = 30): array
    {
        $pages = CrawlPage::whereHas('crawlJob', fn ($q) => $q->where('onboarding_session_id', $session->id))
            ->limit(15)->get();

        $context = $pages->map(fn ($p) => "## {$p->url}\n{$p->title}\n\n" . Str::limit($p->markdown ?? '', 2000))
            ->implode("\n\n---\n\n");

        $sizesList = collect(self::DEFAULT_SIZES)
            ->map(fn ($s) => $s[0] . 'x' . $s[1])
            ->implode(', ');

        $eventsJson = json_encode($events ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $prompt = <<<PROMPT
You are an advertising strategist + brand analyst. From the website crawl below,
produce STRICT JSON with two top-level keys: "brand" and "concepts".

WEBSITE: {$session->website_url}
LOCATION: {$session->business_location}
CAMPAIGN GOAL: {$session->campaign_goal}

CONTENT FROM CRAWL:
{$context}

EVENTS (use 30% of concepts for these when non-empty):
{$eventsJson}

BRAND OUTPUT keys (object):
company_name, industry, business_model, short_description,
target_audiences (array), main_products (array), value_propositions (array),
proof_points (array), brand_voice {tone, personality[], words_to_use[], words_to_avoid[]},
visual_identity {primary_color, secondary_color, accent_color, logo_usage_notes},
ad_compliance_risks (array), recommended_ctas (array).

CONCEPTS OUTPUT (array of {$count} items):
Distribute across these ad sizes: {$sizesList}.
70% concepts ad_type="brand", 30% ad_type="event" when events are present.
Each concept MUST include:
- concept (string)
- ad_type ("brand" or "event")
- size {width:int, height:int}
- headline, subheadline, body, cta (strings; use brand voice; copy must FIT the size)
- visual_direction (string)
- image_prompt (string, MUST end with "no text, no logo, no watermark")
- layout_type (string layout hint, e.g. image-background-with-card-overlay)
- position ("top" | "middle" | "bottom") — where the copy block should sit on the ad canvas
- font_sizes {headline:int, subheadline:int, cta:int} — pixel sizes that visibly FIT
  inside the given width/height with room to breathe (no overflow):
    * 728x90 / 970x250 (leaderboards) — copy on the LEFT, headline 22-34px
    * 160x600 / 300x600 (skyscrapers) — copy STACKED, headline 22-32px
    * 320x50 / 320x100 / 468x60 (mobile/small banners) — tight, headline 14-18px
    * 300x250 / 336x280 / 250x250 (squares) — copy at bottom, headline 22-30px
  Always: subheadline <= headline * 0.6, cta <= headline * 0.55, min 10px.
- The position field must be consistent with the layout: small banners use "middle",
  squares use "bottom", skyscrapers use "bottom", leaderboards use "middle".

GLOBAL RULES:
- No public tragedies, politics, sensitive attributes, fake endorsements or
  unsupported medical/financial claims.
- Output ONLY the top-level JSON object. No prose.
PROMPT;

        $schema = [
            'type'       => 'object',
            'properties' => [
                'brand'    => $this->brandSchema(),
                'concepts' => [
                    'type'  => 'array',
                    'items' => $this->conceptSchema(),
                ],
            ],
            'required' => ['brand', 'concepts'],
        ];

        $model   = (string) config('services.gemini.combined_model', 'gemini-3.5-flash');
        $payload = $this->gemini->generateJson($prompt, $schema, $model);

        if (! $payload || ! isset($payload['brand'], $payload['concepts'])
            || ! is_array($payload['concepts']) || empty($payload['concepts'])) {
            Log::info('GeminiBrandAndAdsService falling back to stubs');
            return [
                'brand'    => $this->stubBrand($session),
                'concepts' => $this->stubConcepts($this->stubBrand($session), $count, $events),
            ];
        }

        // Normalise font_sizes/position if Gemini omitted them on any item.
        $payload['concepts'] = array_map(
            fn (array $c) => $this->fillTypography($c),
            $payload['concepts'],
        );

        return $payload;
    }

    private function brandSchema(): array
    {
        return [
            'type'       => 'object',
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
                'visual_identity' => [
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
    }

    private function conceptSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'concept'          => ['type' => 'string'],
                'ad_type'          => ['type' => 'string'],
                'size'             => [
                    'type'       => 'object',
                    'properties' => [
                        'width'  => ['type' => 'integer'],
                        'height' => ['type' => 'integer'],
                    ],
                    'required' => ['width', 'height'],
                ],
                'headline'         => ['type' => 'string'],
                'subheadline'      => ['type' => 'string'],
                'body'             => ['type' => 'string'],
                'cta'              => ['type' => 'string'],
                'visual_direction' => ['type' => 'string'],
                'image_prompt'     => ['type' => 'string'],
                'layout_type'      => ['type' => 'string'],
                'position'         => ['type' => 'string'],
                'font_sizes'       => [
                    'type'       => 'object',
                    'properties' => [
                        'headline'    => ['type' => 'integer'],
                        'subheadline' => ['type' => 'integer'],
                        'cta'         => ['type' => 'integer'],
                    ],
                    'required' => ['headline', 'subheadline', 'cta'],
                ],
            ],
            'required' => ['concept', 'ad_type', 'size', 'headline', 'cta', 'image_prompt', 'layout_type', 'position', 'font_sizes'],
        ];
    }

    /**
     * Backfill font_sizes + position deterministically from the ad's
     * width/height when Gemini omits them, so downstream rendering always
     * has a typography target.
     */
    private function fillTypography(array $c): array
    {
        $w = (int) ($c['size']['width']  ?? 300);
        $h = (int) ($c['size']['height'] ?? 250);

        if (empty($c['position'])) {
            if ($w >= 728 && $h <= 250) {
                $c['position'] = 'middle';
            } elseif ($h >= $w * 1.5) {
                $c['position'] = 'bottom';
            } else {
                $c['position'] = 'bottom';
            }
        }

        $fs = $c['font_sizes'] ?? [];
        if (! isset($fs['headline'], $fs['subheadline'], $fs['cta'])) {
            $headline = max(12, min(34, (int) round(min($w, $h) * 0.11)));
            // Small mobile banners need a tighter ceiling.
            if ($h <= 100) {
                $headline = max(12, min(18, (int) round($h * 0.30)));
            }
            $c['font_sizes'] = [
                'headline'    => $fs['headline']    ?? $headline,
                'subheadline' => $fs['subheadline'] ?? max(10, (int) round($headline * 0.55)),
                'cta'         => $fs['cta']         ?? max(10, (int) round($headline * 0.50)),
            ];
        }

        return $c;
    }

    private function stubBrand(OnboardingSession $session): array
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
                'tone'           => 'Friendly, confident, modern',
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

    private function stubConcepts(array $brand, int $count, array $events): array
    {
        $angles = [
            ['Problem-solution',  'Stop wasting time on %s.',           'A simple way to keep things on track.'],
            ['Social proof',      'Trusted by busy teams.',             'Thousands of happy customers love %s.'],
            ['Limited time',      'New season, fresh picks.',           'Limited stock — order before it ends.'],
            ['Lifestyle',         'Made for your everyday.',            'Premium quality, simple ordering, fast delivery.'],
            ['Curiosity',         'Wait until you see this.',           'A smarter way to shop %s.'],
            ['Local angle',       'Loved by locals.',                   'Your neighborhood favorite.'],
            ['Promo',             'Save more this week.',               'Special offers for new customers.'],
            ['Feature focus',     'Premium ingredients only.',          'Curated with care, delivered fresh.'],
            ['Trust',             'Quality you can taste.',             'Crafted with the highest standards.'],
            ['Empathy',           'Because mornings are hard enough.',  'A simple lift to your day.'],
        ];
        $ctas      = $brand['recommended_ctas'] ?: ['Shop now', 'Try it free', 'Learn more', 'Order today'];
        $sizes     = self::DEFAULT_SIZES;
        $eventList = $events ?: [];

        $concepts = [];
        for ($i = 0; $i < $count; $i++) {
            $isEvent = $eventList && ($i % 3 === 0);
            $angle   = $angles[$i % count($angles)];
            $size    = $sizes[$i % count($sizes)];
            $cta     = $ctas[$i % count($ctas)];
            $event   = $isEvent ? $eventList[($i / 3) % count($eventList)] ?? null : null;
            $product = $brand['target_audiences'][0] ?? 'busy people';
            $primary = $brand['visual_identity']['primary_color'] ?? '#2563EB';
            $accent  = $brand['visual_identity']['accent_color']  ?? '#7C3AED';

            $concepts[] = $this->fillTypography([
                'concept'     => $angle[0] . ($event ? ' · ' . $event['title'] : ''),
                'ad_type'     => $isEvent ? 'event' : 'brand',
                'size'        => ['width' => (int) $size[0], 'height' => (int) $size[1]],
                'headline'    => sprintf($angle[1], $product),
                'subheadline' => sprintf($angle[2], $brand['company_name'] ?? 'us'),
                'body'        => $brand['short_description'] ?? '',
                'cta'         => $cta,
                'visual_direction' => $event['recommended_angle']
                    ?? 'Modern editorial product photography on a clean light surface, natural daylight, premium commercial style.',
                'image_prompt' => sprintf(
                    'modern editorial photography of %s, bright natural daylight, premium commercial style, soft focus background, %s and %s color accents, no text, no logo, no watermark',
                    strtolower($brand['main_products'][0] ?? $brand['industry'] ?? 'a clean product scene'),
                    $primary,
                    $accent,
                ),
                'layout_type' => $i % 3 === 0 ? 'image-background-with-card-overlay' : ($i % 3 === 1 ? 'split-image-text' : 'centered-card'),
                'news_event'  => $event,
            ]);
        }
        return $concepts;
    }
}
