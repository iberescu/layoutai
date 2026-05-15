<?php

namespace App\Services;

use App\Models\BrandProfile;
use App\Models\Campaign;

class GeminiAdService
{
    public const DEFAULT_SIZES = [
        ['300', 250], ['336', 280], ['728', 90], ['970', 250],
        ['160', 600], ['300', 600], ['320', 50], ['320', 100],
        ['468', 60], ['250', 250],
    ];

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    /**
     * Generate ad concepts (strategy + copy + layout + image prompt).
     * Returns an array of concept dicts.
     */
    public function generateConcepts(Campaign $campaign, BrandProfile $brand, int $count = 30, array $events = []): array
    {
        $context = json_encode([
            'company'    => $brand->company_name,
            'industry'   => $brand->industry,
            'audiences'  => $brand->target_audience_json,
            'voice'      => $brand->brand_voice_json,
            'value_props'=> $brand->visual_identity_json['value_propositions'] ?? null,
            'ctas'       => $brand->ctas_json,
            'proof'      => $brand->proof_points_json,
            'risks'      => $brand->compliance_risks_json,
            'goal'       => $campaign->goal,
            'events'     => $events,
        ], JSON_PRETTY_PRINT);

        $prompt = <<<PROMPT
You are an advertising strategist. Produce {$count} ad concepts as STRICT JSON ARRAY.

BRAND CONTEXT:
{$context}

REQUIREMENTS:
- 70% brand ads, 30% event/location-aware ads (when events given).
- Distribute across sizes 300x250, 336x280, 728x90, 970x250, 160x600, 300x600, 320x50, 320x100, 468x60, 250x250.
- Each concept must include: concept, ad_type ("brand" or "event"), size {width,height},
  headline, subheadline, body, cta, visual_direction, image_prompt, layout_type.
- image_prompt MUST end with: "no text, no logo, no watermark".
- Never reference public tragedies, politics, disasters, sensitive attributes,
  fake endorsements or unsupported medical/financial claims.
PROMPT;

        $schema = ['type' => 'array', 'items' => ['type' => 'object']];
        $result = $this->gemini->generateJson($prompt, $schema);
        if (! $result) {
            $result = $this->stubConcepts($brand, $count, $events);
        }
        return $result;
    }

    private function stubConcepts(BrandProfile $brand, int $count, array $events): array
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
        $ctas      = $brand->ctas_json ?: ['Shop now', 'Try it free', 'Learn more', 'Order today'];
        $sizes     = self::DEFAULT_SIZES;
        $eventList = $events ?: [];

        $concepts = [];
        for ($i = 0; $i < $count; $i++) {
            $isEvent = $eventList && ($i % 3 === 0);
            $angle   = $angles[$i % count($angles)];
            $size    = $sizes[$i % count($sizes)];
            $cta     = $ctas[$i % count($ctas)];
            $event   = $isEvent ? $eventList[($i / 3) % count($eventList)] ?? null : null;
            $product = $brand->target_audience_json[0] ?? 'busy people';

            $concepts[] = [
                'concept'     => $angle[0] . ($event ? ' · ' . $event['title'] : ''),
                'ad_type'     => $isEvent ? 'event' : 'brand',
                'size'        => ['width' => (int) $size[0], 'height' => (int) $size[1]],
                'headline'    => sprintf($angle[1], $product),
                'subheadline' => sprintf($angle[2], $brand->company_name ?? 'us'),
                'body'        => $brand->description ?? '',
                'cta'         => $cta,
                'visual_direction' => $event['recommended_angle']
                    ?? 'Modern editorial product photography on a clean light surface, natural daylight, premium commercial style.',
                'image_prompt' => sprintf(
                    'modern editorial photography of %s, bright natural daylight, premium commercial style, soft focus background, %s and %s color accents, no text, no logo, no watermark',
                    strtolower($brand->main_products_json[0] ?? $brand->industry ?? 'a clean product scene'),
                    $brand->primaryColor(),
                    $brand->accentColor(),
                ),
                'layout_type' => $i % 3 === 0 ? 'image-background-with-card-overlay' : ($i % 3 === 1 ? 'split-image-text' : 'centered-card'),
                'news_event'  => $event,
            ];
        }
        return $concepts;
    }
}
