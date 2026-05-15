<?php

namespace App\Services;

use App\Models\AdVariant;
use App\Models\BrandProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Asks Gemini Flash to write a complete, self-contained HTML document
 * for one ad variant. The runmyprint image URL is passed in as a
 * placeholder Gemini must use, so the final ad is a Gemini-authored
 * layout that embeds the AI-generated image.
 */
class GeminiHtmlAdService
{
    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    public function buildHtml(AdVariant $variant, BrandProfile $brand, ?string $imageUrl, ?string $logoUrl): array
    {
        $prompt = $this->prompt($variant, $brand, $imageUrl, $logoUrl);

        $schema = [
            'type' => 'object',
            'properties' => [
                'html' => ['type' => 'string'],
                'css'  => ['type' => 'string'],
            ],
            'required' => ['html'],
        ];

        $payload = $this->gemini->generateJson($prompt, $schema);
        if ($payload && ! empty($payload['html'])) {
            $html = $this->sanitise((string) $payload['html'], $variant);
            return ['html' => $html, 'css' => $payload['css'] ?? ''];
        }

        Log::info('GeminiHtmlAdService falling back to template service for variant ' . $variant->id);
        return app(AdTemplateService::class)->buildHtml($variant, $brand, $imageUrl, $logoUrl);
    }

    /**
     * Build HTML for many variants in one Gemini call. All variants must share
     * the same BrandProfile (we batch by campaign). Returns:
     *   [variant_id => ['html' => ..., 'css' => ...]]
     * Variants missing from the response (or that fail JSON parsing) are
     * silently omitted — caller falls back per-variant.
     *
     * @param Collection<int,AdVariant> $variants
     * @return array<int,array{html:string,css:string}>
     */
    public function buildHtmlBatch(Collection $variants, BrandProfile $brand, ?string $logoUrl): array
    {
        if ($variants->isEmpty()) {
            return [];
        }

        $prompt = $this->batchPrompt($variants, $brand, $logoUrl);
        $schema = [
            'type' => 'object',
            'properties' => [
                'ads' => [
                    'type'  => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'variant_id' => ['type' => 'integer'],
                            'html'       => ['type' => 'string'],
                            'css'        => ['type' => 'string'],
                        ],
                        'required' => ['variant_id', 'html'],
                    ],
                ],
            ],
            'required' => ['ads'],
        ];

        $payload = $this->gemini->generateJson($prompt, $schema);
        $rows = is_array($payload['ads'] ?? null) ? $payload['ads'] : [];
        $byId = $variants->keyBy('id');

        $out = [];
        foreach ($rows as $row) {
            $vid = (int) ($row['variant_id'] ?? 0);
            $html = (string) ($row['html'] ?? '');
            if ($vid === 0 || $html === '' || ! isset($byId[$vid])) {
                continue;
            }
            $out[$vid] = [
                'html' => $this->sanitise($html, $byId[$vid]),
                'css'  => (string) ($row['css'] ?? ''),
            ];
        }
        return $out;
    }

    private function batchPrompt(Collection $variants, BrandProfile $brand, ?string $logoUrl): string
    {
        $primary   = $brand->primaryColor();
        $accent    = $brand->accentColor();
        $secondary = $brand->secondaryColor();
        $tone      = $brand->brand_voice_json['tone'] ?? 'modern, confident';
        $logo      = $logoUrl ?: '';

        $items = $variants->map(function (AdVariant $v) {
            return [
                'variant_id'  => $v->id,
                'width'       => $v->size_width,
                'height'      => $v->size_height,
                'headline'    => $v->headline ?? '',
                'subheadline' => $v->subheadline ?? '',
                'cta'         => $v->cta ?? 'Learn more',
                'layout'      => $v->layout_type ?? 'image-background-with-card-overlay',
                'image_url'   => $v->image?->stored_url ?? '',
            ];
        })->values()->all();

        $itemsJson = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
You are an expert HTML/CSS designer who builds polished, banner-ad-quality
display ads. You will receive an ARRAY of ad specs and must return STRICT JSON:
{ "ads": [ { "variant_id": <int>, "html": "<full document>", "css": "" }, ... ] }

The "ads" array MUST contain one entry per input spec, with the same variant_id.
Do not reorder, drop, or invent variant_ids.

For each ad, follow these constraints exactly:
- One self-contained HTML document (doctype + html + head + body + inline <style>).
- The body must contain ONE root element that is EXACTLY (width)px wide and (height)px
  tall, with no scrollbars and no overflow.
- Inline all styles in a single <style> tag. No external CSS or fonts. Use system
  fonts: 'Inter', -apple-system, 'Segoe UI', sans-serif.
- Use these brand colors: primary {$primary}, accent {$accent}, secondary {$secondary}.
- Tone of voice: {$tone}.
- Embed the spec's image_url as the visual (via <img> or background-image). Do NOT
  use placeholder URLs.
- If a logo URL is given, place it small and unobtrusive: {$logo}
- Use the supplied headline / subheadline / CTA copy verbatim — do not rewrite.
- Make the CTA look like a clickable button (rounded, contrasting color).
- Apply a subtle dark gradient overlay on the image so text stays readable.
- Scale text to the ad size so headlines fill the available space without overflowing.
- For wide leaderboard formats (width >> height) put copy on the left, image on the right.
- For tall skyscrapers/half-pages (height >> width) stack image on top, copy below.
- For squares / medium rectangles place copy at the bottom over the image.
- Absolutely NO text, watermark, or logo INSIDE the image — those are overlay elements.
- Do NOT output anything outside the top-level JSON object.

Brand context:
- Company: {$brand->company_name}
- Industry: {$brand->industry}
- Description: {$brand->description}

Ad specs (process every entry):
{$itemsJson}
PROMPT;
    }

    private function prompt(AdVariant $variant, BrandProfile $brand, ?string $imageUrl, ?string $logoUrl): string
    {
        $w = $variant->size_width;
        $h = $variant->size_height;
        $primary  = $brand->primaryColor();
        $accent   = $brand->accentColor();
        $secondary= $brand->secondaryColor();
        $tone     = $brand->brand_voice_json['tone'] ?? 'modern, confident';
        $headline    = $variant->headline ?? '';
        $subheadline = $variant->subheadline ?? '';
        $cta         = $variant->cta ?? 'Learn more';
        $layout      = $variant->layout_type ?? 'image-background-with-card-overlay';
        $bgImage = $imageUrl ?: '';
        $logo    = $logoUrl  ?: '';

        return <<<PROMPT
You are an expert HTML/CSS designer who builds polished, banner-ad-quality
display ads. Return STRICT JSON: { "html": "<full document>", "css": "" }.

Constraints (must follow exactly):
- Output ONE self-contained HTML document (doctype + html + head + body + inline <style>).
- The body must contain ONE root element that is EXACTLY {$w}px wide and {$h}px tall,
  with no scrollbars, no horizontal/vertical overflow.
- Inline all styles in a single <style> tag. No external CSS or fonts (browsers
  may not load them in time). Use system fonts: 'Inter', -apple-system, 'Segoe UI', sans-serif.
- Use the brand colors: primary {$primary}, accent {$accent}, secondary {$secondary}.
- Use this AI-generated image as the visual: {$bgImage}
  Embed it via <img> or background-image. Do NOT use placeholder URLs.
- If a logo URL is given, place it small and unobtrusive: {$logo}
- Use the supplied copy verbatim; do NOT change wording:
  headline:    "{$headline}"
  subheadline: "{$subheadline}"
  CTA button:  "{$cta}"
- Tone of voice: {$tone}.
- Layout hint: {$layout}.
- Make the CTA look like a clickable button (rounded, contrasting color).
- Apply a subtle dark gradient overlay on the image to keep text readable.
- Scale text to the ad size so headlines fill the available space without overflowing.
- For wide leaderboard formats ({$w}>{$h} by a lot) put copy on the left, image on the right.
- For tall skyscrapers/half-pages ({$h}>{$w} by a lot) stack image on top, copy below.
- For squares/rectangles place copy at the bottom over the image.
- Absolutely NO text, watermark or logo INSIDE the AI image — those are overlay elements.
- Do NOT output anything outside the JSON object.

Brand context:
- Company: {$brand->company_name}
- Industry: {$brand->industry}
- Description: {$brand->description}
PROMPT;
    }

    /**
     * Light-touch hygiene: force the outer dimensions, kill scrollbars,
     * strip <script> tags so we never inject untrusted JS into the renderer.
     */
    private function sanitise(string $html, AdVariant $variant): string
    {
        $w = $variant->size_width;
        $h = $variant->size_height;

        // Strip any <script> tags Gemini might have added.
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);

        // If no <html> wrapper, wrap it.
        if (! str_contains(strtolower($html), '<html')) {
            $html = "<!doctype html><html><head><meta charset=\"utf-8\"></head><body>{$html}</body></html>";
        }

        // Inject a guard style block right after <head> to clamp sizes.
        $guard = "<style>html,body{margin:0;padding:0;overflow:hidden;width:{$w}px;height:{$h}px;}</style>";
        $html = preg_replace('#<head([^>]*)>#i', "<head$1>{$guard}", $html, 1);

        return $html;
    }
}
