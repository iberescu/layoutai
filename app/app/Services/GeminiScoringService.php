<?php

namespace App\Services;

use App\Models\AdVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Creative scoring via Gemini 2.5 Flash. Each ad's copy + image + brand
 * context is sent to Gemini and rated 0–100 on a fixed rubric. Replaces
 * the earlier TRIBE v2 / Replicate GPU pipeline — faster, ~$0.0001 per
 * ad, no GPU cold-boot.
 *
 * Rubric (each 0–100, score = weighted average):
 *   - clarity (0.25)        : value prop legible in <2s
 *   - brand_fit (0.25)      : matches industry, tone, target audience
 *   - copy_strength (0.20)  : concrete, specific, benefit-led
 *   - cta_visibility (0.15) : CTA stands out and reads as clickable
 *   - visual_appeal (0.15)  : composition + colour + image quality
 */
class GeminiScoringService
{
    private const RUBRIC_WEIGHTS = [
        'clarity'        => 0.25,
        'brand_fit'      => 0.25,
        'copy_strength'  => 0.20,
        'cta_visibility' => 0.15,
        'visual_appeal'  => 0.15,
    ];

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    /**
     * Score a batch of variants in one Gemini call.
     *
     * @param Collection<int,AdVariant> $variants  All must share one brand.
     * @return array<int, array{score:float, rationale:string, subscores:array}>
     *         Keyed by variant_id. Variants Gemini drops are silently omitted.
     */
    public function scoreBatch(Collection $variants): array
    {
        if ($variants->isEmpty()) {
            return [];
        }
        $brand = $variants->first()->campaign?->brandProfile;
        if (! $brand) {
            return [];
        }

        $prompt = $this->prompt($variants, $brand);
        $schema = [
            'type' => 'object',
            'properties' => [
                'scores' => [
                    'type'  => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'variant_id'    => ['type' => 'integer'],
                            'clarity'        => ['type' => 'integer'],
                            'brand_fit'      => ['type' => 'integer'],
                            'copy_strength'  => ['type' => 'integer'],
                            'cta_visibility' => ['type' => 'integer'],
                            'visual_appeal'  => ['type' => 'integer'],
                            'rationale'     => ['type' => 'string'],
                        ],
                        'required' => ['variant_id', 'clarity', 'brand_fit',
                            'copy_strength', 'cta_visibility', 'visual_appeal', 'rationale'],
                    ],
                ],
            ],
            'required' => ['scores'],
        ];

        $model = (string) (config('services.gemini.html_model') ?: config('services.gemini.model'));
        $payload = $this->gemini->generateJson($prompt, $schema, $model);
        if (! $payload || ! is_array($payload['scores'] ?? null)) {
            Log::info('GeminiScoringService: empty payload, scoring '.$variants->count().' variants');
            return [];
        }

        $byId = $variants->keyBy('id');
        $out  = [];
        foreach ($payload['scores'] as $row) {
            $vid = (int) ($row['variant_id'] ?? 0);
            if ($vid === 0 || ! isset($byId[$vid])) {
                continue;
            }
            $sub = [
                'clarity'        => $this->clamp($row['clarity']        ?? 0),
                'brand_fit'      => $this->clamp($row['brand_fit']      ?? 0),
                'copy_strength'  => $this->clamp($row['copy_strength']  ?? 0),
                'cta_visibility' => $this->clamp($row['cta_visibility'] ?? 0),
                'visual_appeal'  => $this->clamp($row['visual_appeal']  ?? 0),
            ];
            $score = 0.0;
            foreach (self::RUBRIC_WEIGHTS as $k => $w) {
                $score += $sub[$k] * $w;
            }
            $out[$vid] = [
                'score'     => round($score, 2),
                'rationale' => (string) ($row['rationale'] ?? ''),
                'subscores' => $sub,
            ];
        }
        return $out;
    }

    private function clamp(mixed $n): int
    {
        $n = (int) $n;
        return max(0, min(100, $n));
    }

    private function prompt(Collection $variants, $brand): string
    {
        $items = $variants->map(function (AdVariant $v) {
            return [
                'variant_id'  => $v->id,
                'width'       => $v->size_width,
                'height'      => $v->size_height,
                'headline'    => $v->headline ?? '',
                'subheadline' => $v->subheadline ?? '',
                'cta'         => $v->cta ?? 'Learn more',
                'image_url'   => $v->image?->stored_url ?? '',
            ];
        })->values()->all();
        $itemsJson = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $tone = $brand->brand_voice_json['tone'] ?? 'modern, confident';

        return <<<PROMPT
You are a senior display-ad creative director rating banner-ad creatives
on a strict 0–100 rubric. Return JSON: { "scores": [ { ... }, ... ] }.

For each ad, rate FIVE dimensions 0–100:
- clarity:        Can a distracted reader grasp the value prop in <2 seconds? (penalise jargon, vague claims, missing benefit)
- brand_fit:      Does it match the brand's industry, tone, audience? (penalise generic stock language unrelated to the brand)
- copy_strength:  Is the copy concrete, specific, action-oriented? (penalise filler words, weak verbs, no quantified benefit)
- cta_visibility: Does the CTA read as a button, contrast with background, sit in a clickable spot? (penalise tiny / blending CTAs)
- visual_appeal:  Composition, hierarchy, typography, colour balance, image quality. (penalise crowded layouts, low contrast, awkward crops)

Also provide a 1-sentence rationale (<=120 chars) explaining the weakest dimension.

Be strict and use the full 0-100 range:
- 85-100: would ship to a major brand campaign
- 65-84:  competent, would A/B-test against stronger
- 45-64:  average, needs another iteration
- 25-44:  weak, rewrite copy or regenerate visual
- 0-24:   broken or off-brand

Brand context:
- Company: {$brand->company_name}
- Industry: {$brand->industry}
- Tone: {$tone}
- Description: {$brand->description}

Ads to score (rate every entry, keep the same variant_id, do not invent IDs):
{$itemsJson}
PROMPT;
    }
}
