<?php

namespace App\Services;

use App\Models\CrawlPage;
use App\Models\OnboardingSession;
use App\Services\BrandImageHarvester;
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

    /**
     * Social sizes (Instagram + Facebook). Static photo-led, no animation.
     * Width x Height:
     *   1080x1080 — Instagram feed square
     *   1080x1350 — Instagram feed portrait
     *   1080x1920 — Instagram / Facebook story
     *   1200x630  — Facebook feed link share
     */
    public const SOCIAL_SIZES = [
        [1080, 1080],
        [1080, 1350],
        [1080, 1920],
        [1200, 630],
    ];

    /**
     * Cohort mix per session — keys are the style tag persisted on each
     * AdVariant. Total: 30 ads. All brand-driven.
     *   - standard : 14 brand-safe IAB display ads
     *   - animated :  7 CSS-animated display ads (no JS, sandbox-safe)
     *   - creative :  4 bold/vibrant/experimental display layouts
     *   - social   :  4 Instagram/Facebook on SOCIAL_SIZES, static, photo-led
     *   - showcase :  1 hero "art piece" — inline SVG illustration + CSS
     *                  animation, no photo, big canvas. The standout ad.
     *
     * Daily / event-driven cohort removed — no longer pulling external news
     * feeds. The daily-events cron + the four event-source services
     * (Weather / Market / TechNews / Holiday) are deactivated.
     */
    public const COHORT_MIX = [
        'standard' => 14,
        'animated' => 7,
        'creative' => 4,
        'social'   => 4,
        'showcase' => 1,
    ];

    /**
     * Canvas size used for the single showcase ad — a big skyscraper so the
     * SVG illustration has room to breathe and read as a "poster" rather
     * than a banner.
     */
    public const SHOWCASE_SIZE = [300, 600];

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    /**
     * @return array{brand: array<string,mixed>, concepts: array<int,array<string,mixed>>}
     */
    /**
     * @param array<int,array<string,mixed>> $brandImages
     *   Top N real images harvested from the crawl (each: {url, alt?, w?, h?}).
     *   Gemini may set `real_image_url` on up to N concepts to use a real
     *   brand image instead of a runmyprint AI image for those tiles.
     */
    public function generate(OnboardingSession $session, array $events = [], ?int $count = null, array $brandImages = []): array
    {
        $count ??= array_sum(self::COHORT_MIX);

        // Concepts call gets a wider window (15 pages × 2000 chars) so product
        // copy and tone inform the 30 concepts.
        $conceptPages = CrawlPage::whereHas('crawlJob', fn ($q) => $q->where('onboarding_session_id', $session->id))
            ->limit(15)->get();
        $context = $conceptPages->map(fn ($p) => "## {$p->url}\n{$p->title}\n\n" . Str::limit($p->markdown ?? '', 2000))
            ->implode("\n\n---\n\n");
        $contextEmpty = trim($context) === '';

        // Brand call gets a much tighter slice (5 pages × 1500 chars). The
        // homepage alone usually identifies the brand; product-detail pages
        // don't help the brand call but do bloat input tokens, dragging
        // latency up to 30s. Trimmed input + faster 2.5-flash model brings
        // the brand call into the 5-10s range.
        $brandPages = $conceptPages->take(5);
        $brandContext = $brandPages->map(fn ($p) => "## {$p->url}\n{$p->title}\n\n" . Str::limit($p->markdown ?? '', 1500))
            ->implode("\n\n---\n\n");

        $logoColors = $session->logo_colors_json ?: [];

        // SPLIT ARCHITECTURE: brand call (~3-5k chars output) THEN concepts call
        // (~25-35k chars output). One big combined call kept hitting Gemini Flash's
        // MAX_TOKENS ceiling at ~20k tokens with no way to recover — splitting
        // gives each call its own output budget and lets brand succeed even when
        // concepts hiccup.

        $conceptsModel = (string) config('services.gemini.combined_model', 'gemini-3.5-flash');
        // Brand call uses the lighter / faster 2.5-flash. Quality is plenty
        // for the 7-field lean schema we ask for; the heavier 3.5-flash is
        // reserved for the concepts call where it's worth the extra latency.
        $brandModel = (string) config('services.gemini.brand_model', 'gemini-2.5-flash');

        // ---- 1) Brand summary ----
        $brandStart = microtime(true);
        $brand = $this->callBrand($session, $brandContext, $contextEmpty, $logoColors, $brandModel);
        $brandMs = (int) round((microtime(true) - $brandStart) * 1000);
        Log::info("GeminiBrandAndAdsService: brand_call session={$session->id} model={$brandModel} pages={$brandPages->count()} elapsed_ms={$brandMs}");
        if (! $brand) {
            Log::warning('GeminiBrandAndAdsService: brand call returned no payload for session '.$session->id);
            throw new \RuntimeException('Gemini brand call returned no data for this site.');
        }

        // ---- 2) Concepts (uses brand as input context, no brand block in output) ----
        $conceptsStart = microtime(true);
        $concepts = $this->callConcepts($session, $context, $brand, $events, $count, $conceptsModel, $brandImages);
        $conceptsMs = (int) round((microtime(true) - $conceptsStart) * 1000);
        Log::info("GeminiBrandAndAdsService: concepts_call session={$session->id} model={$conceptsModel} pages={$conceptPages->count()} elapsed_ms={$conceptsMs}");
        if (empty($concepts)) {
            Log::warning('GeminiBrandAndAdsService: concepts call returned no payload for session '.$session->id);
            throw new \RuntimeException('Gemini concepts call returned no data for this site.');
        }

        // Aspect-ratio aware image binding. Two-pass:
        //   1) validate Gemini's existing assignments — reject any where the
        //      image's ratio bucket doesn't fit the ad slot's bucket (a
        //      landscape photo in a 160x600 skyscraper ends up mostly
        //      cropped-away no matter the smartcrop focal point).
        //   2) for concepts that LOST their image (or where Gemini left it
        //      null but slot/cohort would benefit), bind an unused image
        //      whose bucket fits the slot.
        // Showcase ads always skip real images (SVG-driven).
        $concepts = $this->bindRealImagesByBucket($concepts, $brandImages);

        // Normalise + enforce cohort mix.
        $concepts = array_map(fn (array $c) => $this->fillTypography($c), $concepts);
        $concepts = $this->enforceCohortMix($concepts);

        // Cap distinct AI image prompts at 10 across the campaign. Real-image
        // and showcase variants are exempt (they don't go through runmyprint).
        // Existing prompt_hash caching in AdImageGenerationService deduplicates
        // identical prompts into a single fetch + N reuses — so this turns
        // ~24 runmyprint hits into 10, halving the HTML-build wait time.
        $concepts = $this->capDistinctImagePrompts($concepts, 10);

        return ['brand' => $brand, 'concepts' => $concepts];
    }

    /**
     * Bind real brand images to concepts with bucket-aware aspect-ratio
     * matching. Each harvested URL is used by AT MOST one concept.
     *
     * Pass 1 — validate: if Gemini assigned a URL but the image's bucket
     *   doesn't fit the slot's bucket (landscape image in 160x600
     *   skyscraper, portrait image in 970x250 leaderboard, etc.), null
     *   the assignment so the concept falls back to AI imagery instead
     *   of being shipped mostly cropped-away.
     *
     * Pass 2 — backfill: any image released in pass 1 is offered to
     *   concepts that asked for one but lost it, or to concepts Gemini
     *   left without an image, preferring ad slots whose bucket matches.
     *
     * Squares act as a universal fit. Showcases never get a real image.
     *
     * @param  array<int,array<string,mixed>>  $concepts
     * @param  array<int,array<string,mixed>>  $brandImages
     * @return array<int,array<string,mixed>>
     */
    private function bindRealImagesByBucket(array $concepts, array $brandImages): array
    {
        $imageByUrl = [];
        foreach ($brandImages as $i) {
            $u = $i['url'] ?? null;
            if (! $u) continue;
            $imageByUrl[$u] = $i;
        }

        $used = [];
        // --- Pass 1: validate Gemini's existing assignments ---------------
        foreach ($concepts as $idx => $c) {
            $url = $c['real_image_url'] ?? null;
            if (! $url) continue;
            $img = $imageByUrl[$url] ?? null;
            $sw  = (int) ($c['size']['width']  ?? 0);
            $sh  = (int) ($c['size']['height'] ?? 0);
            $isShowcase = ($c['style'] ?? null) === 'showcase';
            $valid = $img !== null
                && ! $isShowcase
                && ! isset($used[$url])
                && $this->imageFitsSlot($img, $sw, $sh);

            if (! $valid) {
                $concepts[$idx]['real_image_url'] = null;
                continue;
            }
            $used[$url] = true;
        }

        // --- Pass 2: backfill — assign released/unused images to concepts
        // whose bucket + size matches, preferring concepts that originally
        // asked for a real image. Walk concepts in order so the cohort
        // balance (standard → animated → creative → social) stays stable.
        $unusedUrls = array_diff(array_keys($imageByUrl), array_keys($used));
        if (! empty($unusedUrls)) {
            foreach ($concepts as $idx => $c) {
                if (empty($unusedUrls)) break;
                if (! empty($c['real_image_url']))         continue;
                if (($c['style'] ?? null) === 'showcase')  continue;
                $sw = (int) ($c['size']['width']  ?? 0);
                $sh = (int) ($c['size']['height'] ?? 0);
                foreach ($unusedUrls as $k => $u) {
                    if ($this->imageFitsSlot($imageByUrl[$u], $sw, $sh)) {
                        $concepts[$idx]['real_image_url'] = $u;
                        unset($unusedUrls[$k]);
                        $used[$u] = true;
                        break;
                    }
                }
            }
        }

        return $concepts;
    }

    /**
     * Combined fit check: image's aspect-ratio bucket must match the slot's
     * (or one of them is square), AND the image must be at least 70% of the
     * slot dimensions on both axes so it doesn't render blurry under
     * object-fit: cover.
     */
    private function imageFitsSlot(array $img, int $slotW, int $slotH): bool
    {
        $imgBucket  = (string) ($img['bucket'] ?? '');
        $slotBucket = (string) BrandImageHarvester::bucketFor($slotW, $slotH);
        if ($imgBucket === '' || $slotBucket === '') return false;
        $bucketsOk = $imgBucket === 'square'
                     || $slotBucket === 'square'
                     || $imgBucket === $slotBucket;
        if (! $bucketsOk) return false;
        return BrandImageHarvester::imageFitsSlot(
            (int) ($img['w'] ?? 0), (int) ($img['h'] ?? 0),
            $slotW, $slotH,
        );
    }

    /**
     * Walk the concepts in order. For variants that need AI imagery (no
     * real_image_url, not showcase), keep the first $limit unique image
     * prompts as the "image pool". Concepts beyond that get reassigned to
     * one of the existing pool entries (round-robin) so the runmyprint fan-
     * out only has $limit fresh fetches to make.
     *
     * @param  array<int,array<string,mixed>>  $concepts
     * @return array<int,array<string,mixed>>
     */
    private function capDistinctImagePrompts(array $concepts, int $limit): array
    {
        $pool   = [];
        $rrIdx  = 0;
        foreach ($concepts as &$c) {
            $needsAi = empty($c['real_image_url']) && ($c['style'] ?? null) !== 'showcase';
            if (! $needsAi) continue;
            $prompt = trim((string) ($c['image_prompt'] ?? ''));
            if ($prompt === '') continue;

            // Already in pool — keep as-is so the cache hit fires naturally.
            if (in_array($prompt, $pool, true)) continue;

            if (count($pool) < $limit) {
                $pool[] = $prompt;
                continue;
            }
            // Pool full — reassign this concept to one of the existing prompts.
            $c['image_prompt'] = $pool[$rrIdx % count($pool)];
            $rrIdx++;
        }
        unset($c);
        return $concepts;
    }

    private function callBrand(OnboardingSession $session, string $context, bool $contextEmpty, array $logoColors, string $model): ?array
    {
        $logoBlock = '';
        if (! empty($logoColors)) {
            $primary   = $logoColors[0] ?? '#2563EB';
            $accent    = $logoColors[1] ?? $primary;
            $secondary = $logoColors[2] ?? $accent;
            $logoBlock = "\nBRAND PALETTE (extracted from logo — use these EXACTLY in visual_identity):\n"
                . "  primary={$primary}  accent={$accent}  secondary={$secondary}\n"
                . "  full_palette=" . implode(', ', $logoColors);
        }
        $groundingRule = $contextEmpty
            ? "GROUNDING (crawl empty): do NOT infer industry / products / model from the domain name. Set neutral / conservative values."
            : "GROUNDING: use ONLY the content from crawl. If a field isn't supported by the crawl, leave it neutral.";

        $prompt = <<<PROMPT
You are a brand analyst. Read the website crawl and produce STRICT JSON
describing the brand. Each string field is ONE short sentence (<= 120 chars).

WEBSITE: {$session->website_url}
LOCATION: {$session->business_location}

CONTENT FROM CRAWL:
{$context}
{$logoBlock}

{$groundingRule}

Output exactly these fields, nothing else:
  company_name      (string, ≤ 60 chars)
  industry          (string, ≤ 60 chars)
  business_model    (string, ≤ 60 chars)
  short_description (string, ≤ 200 chars — ONE sentence)
  brand_voice       { tone (≤ 80 chars) }
  visual_identity   { primary_color (#hex), secondary_color (#hex), accent_color (#hex) }

JSON only. No prose. No additional fields.
PROMPT;

        // Lean schema only — every array field (target_audiences,
        // value_propositions, etc.) was making Gemini balloon to 20+ KB and
        // hit MAX_TOKENS. Downstream code only reads company_name, industry,
        // short_description, brand_voice.tone, and visual_identity colors —
        // so that's all we ask for. ~2500-token ceiling (≈10k chars output).
        // Arrays are padded with empties below for back-compat with persistence.
        $payload = $this->gemini->generateJson($prompt, $this->brandSchemaLean(), $model, 90, 2500);
        if (! $payload) return null;

        return $payload + [
            'target_audiences'    => [],
            'main_products'       => [],
            'value_propositions'  => [],
            'proof_points'        => [],
            'recommended_ctas'    => [],
            'ad_compliance_risks' => [],
        ];
    }

    /**
     * Slim brand schema: only the fields downstream code actually reads.
     * Drops every array field — those were making Gemini's response explode
     * past MAX_TOKENS even at 32k output budget. The user can refine the
     * dropped arrays on the dashboard once the campaign is generated.
     */
    private function brandSchemaLean(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'company_name'      => ['type' => 'string'],
                'industry'          => ['type' => 'string'],
                'business_model'    => ['type' => 'string'],
                'short_description' => ['type' => 'string'],
                'brand_voice' => [
                    'type'       => 'object',
                    'properties' => ['tone' => ['type' => 'string']],
                ],
                'visual_identity' => [
                    'type'       => 'object',
                    'properties' => [
                        'primary_color'   => ['type' => 'string'],
                        'secondary_color' => ['type' => 'string'],
                        'accent_color'    => ['type' => 'string'],
                    ],
                ],
            ],
            'required' => ['company_name', 'industry'],
        ];
    }

    private function callConcepts(OnboardingSession $session, string $context, array $brand, array $events, int $count, string $model, array $brandImages = []): array
    {
        $displaySizesList = collect(self::DEFAULT_SIZES)->map(fn ($s) => $s[0] . 'x' . $s[1])->implode(', ');
        $socialSizesList  = collect(self::SOCIAL_SIZES)->map(fn ($s) => $s[0] . 'x' . $s[1])->implode(', ');
        $cohortMixCounts  = self::COHORT_MIX;
        $cohortJson       = json_encode($cohortMixCounts, JSON_UNESCAPED_SLASHES);

        // Trimmed image catalog for the prompt (url + alt + dims).
        $brandImageCatalog = array_map(fn ($i) => [
            'url'    => $i['url']    ?? null,
            'alt'    => $i['alt']    ?? null,
            'w'      => $i['w']      ?? null,
            'h'      => $i['h']      ?? null,
            'bucket' => $i['bucket'] ?? null,
        ], $brandImages);
        $brandImagesJson = json_encode($brandImageCatalog, JSON_UNESCAPED_SLASHES);
        $brandImagesCount = count($brandImageCatalog);

        $brandJson        = json_encode([
            'company_name'        => $brand['company_name']        ?? null,
            'industry'            => $brand['industry']            ?? null,
            'short_description'   => $brand['short_description']   ?? null,
            'value_propositions'  => $brand['value_propositions']  ?? [],
            'target_audiences'    => $brand['target_audiences']    ?? [],
            'brand_voice'         => $brand['brand_voice']         ?? [],
            'visual_identity'     => $brand['visual_identity']     ?? [],
            'recommended_ctas'    => $brand['recommended_ctas']    ?? [],
        ], JSON_UNESCAPED_SLASHES);

        $prompt = <<<PROMPT
You are a senior display-ad creative director. Return STRICT JSON:
{ "concepts": [ {...}, {...}, ... ] }   exactly {$count} items.

BRAND CONTEXT (use the brand voice + value props + colors here):
{$brandJson}

BRAND IMAGES (real photographs harvested from the brand's website). Each
entry has a `bucket` field of "landscape", "portrait", or "square". ONLY
assign an image's URL to a concept whose ad size matches that bucket:
  - landscape image → landscape slots (970x250, 320x100, 468x60, 1200x630, 300x250, 336x280)
  - portrait image  → portrait slots  (160x600, 300x600, 1080x1350, 1080x1920)
  - square image    → any slot
Pick up to {$brandImagesCount} concepts (NOT the showcase) and set
`real_image_url` to the matching URL. For every other concept, leave
real_image_url null and provide an image_prompt for AI generation. (We
post-validate bucket compatibility and will null any mismatched assignment.)
{$brandImagesJson}

COHORT MIX (exact counts):
{$cohortJson}

Each concept tags itself with `style` and `platform`:
  - "standard"  display  → {$cohortMixCounts['standard']} items, IAB sizes ({$displaySizesList}), brand-safe layouts
  - "animated"  display  → {$cohortMixCounts['animated']} items, IAB sizes, set animation_hint
  - "creative"  display  → {$cohortMixCounts['creative']} items, IAB sizes, bold/vibrant/experimental
  - "social"    social   → {$cohortMixCounts['social']} items, SOCIAL SIZES ONLY ({$socialSizesList})
  - "showcase"  display  → {$cohortMixCounts['showcase']} item, 300x600, no photo (SVG-driven hero)

CRITICAL — KEEP EACH FIELD SHORT:
  concept ≤ 40 chars; headline ≤ 60; subheadline ≤ 80; cta ≤ 22;
  image_prompt ≤ 240 chars and MUST end with "no text, no logo, no watermark".
Use the brand voice. Never paragraphs.

Each concept MUST include: concept, ad_type="brand", style, platform,
animation_hint (only for style=animated), size {width,height}, headline,
subheadline, cta, image_prompt, layout_type, position ("top"|"middle"|"bottom"),
font_sizes {headline,subheadline,cta}.

Font sizes that fit (px):
  970x250 leaderboard headline 22-34; 160x600 / 300x600 skyscraper 22-32;
  small banners 14-18; squares 22-30; 1080x1080/1350 IG 48-72;
  1080x1920 story 56-88; 1200x630 FB 40-60.
subheadline <= headline*0.6; cta <= headline*0.55; min 10px.

GLOBAL RULES: no tragedies/politics/sensitive attributes/fake endorsements/
unsupported medical or financial claims. JSON only — no prose.
PROMPT;

        $schema = [
            'type'       => 'object',
            'properties' => [
                'concepts' => [
                    'type'  => 'array',
                    'items' => $this->conceptSchema(),
                ],
            ],
            'required' => ['concepts'],
        ];

        // 5 min HTTP ceiling. 50k output budget — concepts dominate the response
        // now that brand lives in a separate call.
        $payload = $this->gemini->generateJson($prompt, $schema, $model, 300, 50000);
        return is_array($payload['concepts'] ?? null) ? $payload['concepts'] : [];
    }

    /**
     * Re-tag overflow concepts so the (style, platform) mix matches COHORT_MIX
     * exactly. If Gemini returns 5 "social" but we asked for 10, the next
     * standard/creative tails get retagged + resized to a social canvas.
     * Animated tail items get an animation_hint backfilled if missing.
     *
     * @param array<int,array<string,mixed>> $concepts
     * @return array<int,array<string,mixed>>
     */
    private function enforceCohortMix(array $concepts): array
    {
        $animHints = ['fade-stagger','slide-in','kinetic-headline','pulse-cta','parallax-image',
            'gradient-shift','marquee','wipe-reveal','scale-in','typing'];

        $buckets = ['standard' => [], 'animated' => [], 'creative' => [], 'social' => [], 'showcase' => []];
        foreach ($concepts as $i => $c) {
            $style = strtolower((string) ($c['style'] ?? 'standard'));
            if (! isset($buckets[$style])) {
                $style = 'standard';
            }
            $buckets[$style][] = $i;
        }

        $out = $concepts;

        // Re-tag tail items from overflowing buckets into short buckets.
        foreach (self::COHORT_MIX as $targetStyle => $want) {
            while (count($buckets[$targetStyle]) < $want) {
                $donor = null;
                $overflow = 0;
                foreach (self::COHORT_MIX as $s => $w) {
                    $extra = count($buckets[$s]) - $w;
                    if ($s !== $targetStyle && $extra > $overflow) {
                        $donor = $s;
                        $overflow = $extra;
                    }
                }
                if (! $donor) break;

                $idx = array_pop($buckets[$donor]);
                $out[$idx]['style']    = $targetStyle;
                $out[$idx]['platform'] = $targetStyle === 'social' ? 'social' : 'display';
                if ($targetStyle === 'daily') {
                    $out[$idx]['ad_type'] = 'event';
                } else {
                    $out[$idx]['ad_type'] = $out[$idx]['ad_type'] ?? 'brand';
                }
                if ($targetStyle === 'social') {
                    $size = self::SOCIAL_SIZES[count($buckets['social']) % count(self::SOCIAL_SIZES)];
                    $out[$idx]['size'] = ['width' => $size[0], 'height' => $size[1]];
                    $out[$idx] = $this->fillTypography($out[$idx]);
                    $out[$idx]['position'] = $out[$idx]['position'] ?? 'bottom';
                } elseif ($targetStyle === 'showcase') {
                    $out[$idx]['size'] = ['width' => self::SHOWCASE_SIZE[0], 'height' => self::SHOWCASE_SIZE[1]];
                    $out[$idx] = $this->fillTypography($out[$idx]);
                }
                $buckets[$targetStyle][] = $idx;
            }
        }

        // Final pass: defaults + round-robin animation hints (Gemini tends to
        // collapse to a single hint across the whole batch — force variety).
        $animIdx = 0;
        foreach ($out as &$c) {
            $c['style']    = $c['style']    ?? 'standard';
            $c['platform'] = $c['style'] === 'social' ? 'social' : 'display';
            // Daily cohort removed — any stale "daily" tags from old data fall back to standard.
            if (($c['style'] ?? null) === 'daily') {
                $c['style'] = 'standard';
            }
            if ($c['style'] === 'animated') {
                $c['animation_hint'] = $animHints[$animIdx % count($animHints)];
                $animIdx++;
            } else {
                $c['animation_hint'] = null;
            }
            // Showcase ads use inline SVG art — no photo needed. Lock the
            // canvas to the showcase size so the SVG composition lands at
            // the right aspect ratio.
            if ($c['style'] === 'showcase') {
                $c['size']     = ['width' => self::SHOWCASE_SIZE[0], 'height' => self::SHOWCASE_SIZE[1]];
                $c['position'] = $c['position'] ?? 'bottom';
                $c['layout_type'] = 'svg-art-poster';
                // Headline can scale up since there's no photo competing.
                $c['font_sizes']['headline']    = max(36, (int) ($c['font_sizes']['headline']    ?? 42));
                $c['font_sizes']['subheadline'] = max(16, (int) ($c['font_sizes']['subheadline'] ?? 20));
                $c['font_sizes']['cta']         = max(14, (int) ($c['font_sizes']['cta']         ?? 16));
            }
            // Social canvases need typography big enough to read at 1080+.
            if ($c['style'] === 'social') {
                $h = (int) ($c['size']['height'] ?? 1080);
                $minHead = $h >= 1900 ? 64 : ($h >= 1300 ? 52 : 44);
                $c['font_sizes']['headline']    = max($minHead, (int) ($c['font_sizes']['headline']    ?? $minHead));
                $c['font_sizes']['subheadline'] = max(22, (int) ($c['font_sizes']['subheadline'] ?? round($c['font_sizes']['headline'] * 0.55)));
                $c['font_sizes']['cta']         = max(20, (int) ($c['font_sizes']['cta']         ?? round($c['font_sizes']['headline'] * 0.45)));
            }
        }
        return $out;
    }

    private function brandSchema(): array
    {
        // Gemini's structured-output JSON Schema subset doesn't accept
        // maxLength / maxItems — using them yields a 400 INVALID_ARGUMENT.
        // Output sizes are bounded instead by the prompt + split-call shape.
        $stringArr = ['type' => 'array', 'items' => ['type' => 'string']];
        return [
            'type'       => 'object',
            'properties' => [
                'company_name'        => ['type' => 'string'],
                'industry'            => ['type' => 'string'],
                'business_model'      => ['type' => 'string'],
                'short_description'   => ['type' => 'string'],
                'target_audiences'    => $stringArr,
                'main_products'       => $stringArr,
                'value_propositions'  => $stringArr,
                'proof_points'        => $stringArr,
                'recommended_ctas'    => $stringArr,
                'ad_compliance_risks' => $stringArr,
                'brand_voice'         => [
                    'type'       => 'object',
                    'properties' => [
                        'tone'           => ['type' => 'string'],
                        'personality'    => $stringArr,
                        'words_to_use'   => $stringArr,
                        'words_to_avoid' => $stringArr,
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
        // No maxLength/maxItems — Gemini rejects those with 400 INVALID_ARGUMENT.
        // The prompt carries hard length caps + the split-call shape (brand
        // and concepts in separate Gemini requests) keeps each response below
        // the model's MAX_TOKENS ceiling.
        return [
            'type'       => 'object',
            'properties' => [
                'concept'          => ['type' => 'string'],
                'ad_type'          => ['type' => 'string'],
                'style'            => ['type' => 'string'],
                'platform'         => ['type' => 'string'],
                'animation_hint'   => ['type' => 'string'],
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
                'cta'              => ['type' => 'string'],
                'image_prompt'     => ['type' => 'string'],
                // When set, the HTML pipeline uses this real harvested image
                // and SKIPS the runmyprint AI fetch for this variant.
                'real_image_url'   => ['type' => 'string'],
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
            'required' => ['concept', 'ad_type', 'style', 'size', 'headline', 'cta', 'image_prompt', 'position', 'font_sizes'],
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
