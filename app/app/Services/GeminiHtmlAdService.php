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

        // The HTML pipeline batches by style, so every batch is homogeneous.
        // Send only the directive block for THIS batch's style — saves ~5k
        // input tokens per call (was shipping all 6 cohort blocks every time).
        $batchStyle = strtolower((string) ($variants->first()->style ?? 'standard'));
        $cohortBlock = $this->cohortDirective($batchStyle);

        $items = $variants->map(function (AdVariant $v) {
            $meta = $v->meta ?? [];
            $fs   = $meta['font_sizes'] ?? [];
            // Smartcrop focal point — apply as object-position so the subject
            // stays in frame on every ad's crop. Defaults to centre when the
            // image isn't focal-tagged (e.g. focal endpoint failed).
            $fx = $v->image?->focal_x;
            $fy = $v->image?->focal_y;
            return [
                'variant_id'        => $v->id,
                'width'             => $v->size_width,
                'height'            => $v->size_height,
                'headline'          => $v->headline ?? '',
                'subheadline'       => $v->subheadline ?? '',
                'cta'               => $v->cta ?? 'Learn more',
                'layout'            => $v->layout_type ?? 'image-background-with-card-overlay',
                'image_url'         => $v->image?->stored_url ?? '',
                'image_focal_x'     => $fx !== null ? (float) $fx : 50.0,
                'image_focal_y'     => $fy !== null ? (float) $fy : 50.0,
                'position'          => $meta['position'] ?? 'bottom',
                'style'             => $v->style ?? 'standard',
                'platform'          => $v->platform ?? 'display',
                'animation_hint'    => $meta['animation_hint'] ?? null,
                'font_size_headline'    => (int) ($fs['headline']    ?? 0),
                'font_size_subheadline' => (int) ($fs['subheadline'] ?? 0),
                'font_size_cta'         => (int) ($fs['cta']         ?? 0),
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
- Apply the spec's smartcrop focal point so the subject stays in frame when the
  image is cropped to the ad's aspect ratio. CSS:
    img        { object-fit: cover; object-position: spec.image_focal_x% spec.image_focal_y%; }
    background { background-position: spec.image_focal_x% spec.image_focal_y%; background-size: cover; }
  Default 50% 50% only when both values are exactly 50.
- If a logo URL is given, place it small and unobtrusive: {$logo}
- Use the supplied headline / subheadline / CTA copy verbatim — do not rewrite.
- Make the CTA look like a clickable button (rounded, contrasting color).
- Apply a subtle dark gradient overlay on the image so text stays readable.
- TYPOGRAPHY: Use the spec's font_size_headline / font_size_subheadline / font_size_cta
  values (in px) as your starting font-size for those three elements. If any value is 0
  the spec didn't pin it — pick something reasonable for the ad size. You may shrink
  by up to 10% to prevent overflow; never grow them.
- COPY POSITION: place the copy block per spec.position:
  * "top"    — copy in the top 40% of the ad
  * "middle" — copy vertically centered
  * "bottom" — copy in the bottom 40% of the ad
- For wide leaderboard formats (width >> height) put copy on the left, image on the right.
- For tall skyscrapers/half-pages (height >> width) stack image on top, copy below.
- Absolutely NO text, watermark, or logo INSIDE the image — those are overlay elements.
- NO <script> tags. NO JavaScript anywhere. The renderer iframes are sandbox-locked,
  scripts are stripped, and animation must be pure CSS.
- Do NOT output anything outside the top-level JSON object.

{$cohortBlock}

Brand context:
- Company: {$brand->company_name}
- Industry: {$brand->industry}
- Description: {$brand->description}

Ad specs (process every entry):
{$itemsJson}
PROMPT;
    }

    /**
     * Per-style directive block. Returned alone (not all 6 cohorts concatenated)
     * because the HTML pipeline batches by style — sending all cohort blocks in
     * every call was burning ~5k input tokens / call for no benefit.
     */
    private function cohortDirective(string $style): string
    {
        return match ($style) {
            'creative' => <<<'BLK'
STYLE: creative — BOLD, vibrant, editorial. Break the safe-banner grid. Mix two
contrasting colours from the brand palette as full-bleed backgrounds. Oversized
headline (push toward the font_size_headline ceiling). Asymmetric composition,
deliberate overlap of text and image, magazine-poster aesthetic. Use one extra
decorative element (a circle, a stripe, a number) for visual rhythm. Never feel
corporate-safe.
BLK,
            'animated' => <<<'BLK'
STYLE: animated — CSS-only motion (NO JS). Use the spec's animation_hint as the
primary motion pattern. EVERY animation must loop — `animation-iteration-count:
infinite` is REQUIRED on every @keyframes declaration. Static fade-ins that run
once are NOT acceptable. Total cycle ≤ 6s. Animations must not move the ad's
root container; only its INSIDE elements.

Use these EXACT recipes per animation_hint (adapt selectors to your markup):

  "fade-stagger" — cycle headline + sub + CTA fading in and out together every 4s:
    @keyframes fade-loop { 0%,15%,85%,100% {opacity:0} 30%,70% {opacity:1} }
    .headline    { animation: fade-loop 4s ease-in-out infinite; }
    .subheadline { animation: fade-loop 4s ease-in-out infinite 0.3s; }
    .cta         { animation: fade-loop 4s ease-in-out infinite 0.6s; }

  "slide-in" — headline + sub slide in from the left, hold, slide out to the right:
    @keyframes slide-loop { 0%{transform:translateX(-30px);opacity:0} 20%,80%{transform:translateX(0);opacity:1} 100%{transform:translateX(30px);opacity:0} }
    .headline    { animation: slide-loop 5s ease-in-out infinite; }
    .subheadline { animation: slide-loop 5s ease-in-out infinite 0.4s; }

  "kinetic-headline" — headline scale-pulses subtly every 2.5s, infinitely:
    @keyframes kinetic { 0%,100%{transform:scale(1)} 50%{transform:scale(1.05)} }
    .headline { animation: kinetic 2.5s ease-in-out infinite; transform-origin: left center; }

  "pulse-cta" — CTA button breathes (scale 1 → 1.08 → 1) every 2s, infinitely:
    @keyframes pulse { 0%,100%{transform:scale(1);box-shadow:0 0 0 0 rgba(0,0,0,0.15)} 50%{transform:scale(1.08);box-shadow:0 0 0 8px rgba(0,0,0,0)} }
    .cta { animation: pulse 2s ease-in-out infinite; }

  "parallax-image" — image very slowly zooms 1 → 1.1 → 1, infinitely, 8s cycle:
    @keyframes ken { 0%,100%{transform:scale(1)} 50%{transform:scale(1.10)} }
    .image { animation: ken 8s ease-in-out infinite; transform-origin: center; }

  "gradient-shift" — 3-stop gradient with 200% size, background-position loops 0→100→0, 6s, infinite:
    background: linear-gradient(135deg, COLOR_A, COLOR_B, COLOR_C); background-size: 200% 200%;
    @keyframes gshift { 0%,100%{background-position:0% 50%} 50%{background-position:100% 50%} }
    .bg { animation: gshift 6s ease-in-out infinite; }

  "marquee" — brand-colour ribbon scrolls right-to-left infinitely:
    @keyframes marquee { 0%{transform:translateX(0)} 100%{transform:translateX(-50%)} }
    .ribbon-track { animation: marquee 12s linear infinite; display:flex; }
    (duplicate the ribbon text inside .ribbon-track for a seamless wrap)

  "wipe-reveal" — clip-path inset wipe across the copy block, 5s loop:
    @keyframes wipe { 0%,100%{clip-path:inset(0 100% 0 0)} 30%,70%{clip-path:inset(0 0 0 0)} }
    .copy { animation: wipe 5s ease-in-out infinite; }

  "scale-in" — whole copy card pulses scale 0.96 → 1 → 0.96 every 3.5s:
    @keyframes scale-pulse { 0%,100%{transform:scale(0.96);opacity:0.85} 50%{transform:scale(1);opacity:1} }
    .copy-card { animation: scale-pulse 3.5s ease-in-out infinite; }

  "typing" — headline width grows 0 → 100% with blinking caret, infinitely 4s:
    @keyframes type   { 0%,8%{width:0} 50%,90%{width:100%} 100%{width:0} }
    @keyframes blink  { 0%,49%{opacity:1} 50%,100%{opacity:0} }
    .headline { overflow:hidden; white-space:nowrap; border-right:2px solid currentColor; width:0; animation: type 4s steps(60,end) infinite, blink 0.6s step-end infinite; }

@media (prefers-reduced-motion: reduce) { * { animation: none !important; } }
BLK,
            'showcase' => <<<'BLK'
STYLE: showcase — THE hero ad. 300×600. NO photo. The entire visual is an
inline <svg> illustration you draw from scratch, layered under headline + CTA.
Goal: a small "motion poster" — art-directed, distinctive, on-brand, never generic.

Hard rules:
  - Root element exactly 300px × 600px, position:relative, overflow:hidden.
  - Background is SVG you compose. NO <img>, NO background-image URL — only
    inline <svg> + CSS. If spec.image_url is set, IGNORE it.
  - SVG fills the canvas. viewBox like "0 0 300 600".
  - Use brand primary + accent + secondary as the SVG fill/stroke palette.
  - Compose multiple SVG primitives (rect, circle, path, polygon, ellipse).
    Pick ONE of these compositions that fits the brand:
      (a) ORBITS: 5-9 circles orbiting a bright center; animate stroke-dasharray + rotate.
      (b) BLOBS: 3-5 overlapping blob <path>s; animate transform: translate + scale.
      (c) GRID PULSE: 6×12 grid of small rects; animate fill-opacity with staggered delays.
      (d) ASCENDING LINES: stack of diagonal stroked lines; animate stroke-dashoffset.
      (e) MARK SUN: one big circle with radial rays, rotating behind a bold word.
      (f) PARTICLES: 12-30 small <circle>s; animate cy/cx with CSS keyframes.
      (g) TYPE STACK: oversized <text> with mix-blend-mode: difference, translateY-ing.
  - At least TWO @keyframes, both `infinite`. Total motion ≤ 8s cycle.
  - Brand wordmark top OR bottom; big headline middle; small rounded CTA pill near bottom.
  - prefers-reduced-motion: reduce { animation: none } on everything.
  - The ad must read well as a STATIC poster — animations enhance, not carry, the design.
BLK,
            'social' => <<<'BLK'
STYLE: social — Instagram/Facebook native. Photo dominates ≥75% of canvas. Copy
sits on a SOLID brand-colour block at the top or bottom edge (per spec.position),
leaving a generous safe margin. Headlines are LARGE (use the headline px value).
CTA is a small rounded pill, not a full-width button. No leaderboard-style
horizontal split. Stories (1080×1920) get extra top + bottom padding so platform
UI doesn't crop the copy.
BLK,
            'daily' => <<<'BLK'
STYLE: daily — event-driven brand ad. Connect the supplied headline/sub to the
current real-world event (weather / market / tech / holiday) referenced in the
concept. Keep the brand visually dominant — the event is the angle, not the
subject. Use a small contextual chip (e.g. "Sunny week ahead") in a brand-accent
color near the top corner to make the event tie-in visible at a glance.
BLK,
            default => <<<'BLK'
STYLE: standard — safe corporate display. Clean grid, generous breathing room,
one strong focal point. The default; don't go off-script.
BLK,
        };
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

        $meta    = $variant->meta ?? [];
        $fs      = $meta['font_sizes'] ?? [];
        $fsH     = (int) ($fs['headline']    ?? 0);
        $fsS     = (int) ($fs['subheadline'] ?? 0);
        $fsC     = (int) ($fs['cta']         ?? 0);
        $position = $meta['position'] ?? 'bottom';

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
- TYPOGRAPHY (use as starting font-size in px; you may shrink up to 10% to avoid
  overflow but never grow them; 0 means unpinned, choose something sensible):
    headline    = {$fsH}px
    subheadline = {$fsS}px
    cta         = {$fsC}px
- COPY POSITION = "{$position}":
    * "top"    → copy lives in the top 40% of the ad
    * "middle" → copy vertically centered
    * "bottom" → copy lives in the bottom 40% of the ad
- For wide leaderboard formats ({$w}>{$h} by a lot) put copy on the left, image on the right.
- For tall skyscrapers/half-pages ({$h}>{$w} by a lot) stack image on top, copy below.
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
