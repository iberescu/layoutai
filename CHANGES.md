# Layout.ai — Build Changelog

Running log of the implementation. The MVP plan started with the
`LayoutAI_Tech_Plan.pdf` (version 1.0, May 15, 2026); this file then
tracks every meaningful change since.

## Tech stack

- **Backend** — Laravel 11 (PHP 8.4) monolith
- **Frontend** — Blade + Tailwind CSS Play CDN + Alpine.js
- **DB** — PostgreSQL 16, `jsonb` for brand + strategy columns
- **Queue** — Redis 7 + Laravel Queues
- **Image gen** — runmyprint `image2.php?prompt=…`
- **AI text** — Gemini 3.5 Flash (brand+ads merged call) + Gemini 2.5 Flash (per-ad HTML)
- **Creative scoring** — Gemini vision (`GeminiScoringService`), batched async
- **Optional rasterize** — Node + Playwright (Chromium); kept off the critical path
- **Storage** — local `public` disk, shared volume across containers in prod

External services (Cloudflare crawl, Gemini, runmyprint, Replicate) all
wrapped in service classes. Each falls back to deterministic stub data so
the full pipeline runs offline / in CI.

## Architecture

```
layout.ai/
  app/                          Laravel app
    app/Jobs/                   queue jobs (top-up cron, scoring, HTML build, etc.)
    app/Services/
      GeminiBrandAndAdsService  one merged call: brand profile + 10 concepts
      GeminiHtmlAdService       per-ad HTML generator (batched x5)
      TemplateAdRenderer        fills the 20 pre-built templates (no Gemini)
      FontMatchingService       brand fonts → closest Google Fonts
      EcommerceDetector         shop + platform detection
      ProductScraper            products (title/price/image) from the crawl
      AdImageGenerationService  runmyprint + SHA-256 cache w/ file-existence validate
      AdTemplateService         deterministic HTML fallback
      AdRenderService           Playwright bridge (idle on prod)
      CloudflareCrawler         async-poll + direct-HTTP fallback
      GeminiScoringService      Gemini-vision creative score w/ mock fallback
      NewsEventService          daily-event hooks
  docker/
    app/                        PHP 8.4-FPM container
    nginx/                      dev nginx vhost
    renderer/                   Node + Playwright /render + /focal service
  deploy/
    docker-compose.prod.yml     prod compose (shared storage volume, scheduler)
    Dockerfile.app.prod         baked-code app image (no source mounts)
    cloud-init.yaml             droplet bootstrap (Docker, ufw, systemd unit)
    deploy.sh                   one-shot DO provision + rsync + compose up
    nginx.conf                  prod vhost
  docker-compose.yml            dev compose (volume-mounted source)
```

## Pipeline (current)

```
CrawlWebsiteJob                  (crawl)
  → ExtractBrandJob              (ai)
  → SummarizeBrandWithGeminiJob  (ai)    brand summary; ecommerce detection;
                                          10 concepts UNLESS the site is a shop
                                          (also: font matching → fonts_json)
  → GenerateTemplateAdsJob       (ai)    20 template ads, no Gemini, HTML built locally
  → GenerateProductAdsJob        (ai)    +20 product ads when the site is a shop
  → GenerateAdImagePromptsJob    (ai)
  → GenerateAdImagesJob          (image) fans out GenerateAdImageJob + BuildAdHtmlBatchJob
                                          for the 10 Gemini ads only (template/product skipped)
  → GenerateAdTemplatesJob       (ai)    finalizer: polls for HTML done, flips preview_ready
```

Base ad set: **20 template + 10 Gemini = 30** for non-shops; **20 template +
20 product = 40** for shops (no Gemini ads).
Per-variant fan-out for the 10 Gemini ads: `GenerateAdImageJob` →
`BuildAdHtmlBatchJob` (5-at-a-time). Scoring runs async post-preview via
`ScoreAdVariantsBatchJob` (`GeminiScoringService`).

Hourly: `layout:top-up-campaigns` (Laravel scheduler) tops every campaign
up to 100 variants. Per-campaign `Cache::lock` prevents the CLI command
and the scheduler from racing each other.

## Significant changes

### Pre-built templates, brand-font matching & ecommerce product ads (2026-06-07)
The initial ad set is no longer 30 Gemini-authored ads:
- **Non-shops** → **20 template + 10 Gemini = 30**.
- **Shops** → **20 template + 20 product = 40** (the 10 Gemini concept ads are
  skipped — the concepts call is never made, saving cost — since product
  remarketing ads are the stronger creative for ecommerce).

- **20 template ads, no Gemini.** `app/resources/ad-templates/` holds 20
  hand-built, screenshot-validated HTML templates (one fixed IAB/social size
  each) with `{{token}}` slots. `TemplateAdRenderer` fills them
  deterministically from the brand — palette, matched Google Fonts, logo,
  harvested imagery, and copy sourced from brand fields (`description`,
  `proof_points`, `ctas`, `company`/`industry`). `GenerateTemplateAdsJob`
  builds all 20 with finished HTML up front (no per-ad image gen, no Gemini),
  so the finalizer + scorer pick them up alongside the 10 Gemini ads.
  - Imagery comes from `BrandImageHarvester` (logo/icon-filtered, aspect-
    bucketed); templates fall back to a brand-gradient when no photo fits.
  - **Adaptive logo chip**: the renderer samples logo luminance and flips the
    chip to a dark background for light/white logos so they never vanish.
  - `GeminiBrandAndAdsService::COHORT_MIX` reduced from 30 → 10.
- **Brand-font matching.** `FontMatchingService` reads `font-family` /
  `@font-face` / Google-Fonts `<link>` from the homepage + its stylesheets
  (and can infer from the logo via Gemini vision), then maps each typeface to
  the closest **Google Font** (curated `config/google_fonts_map.php` +
  Gemini fallback constrained to a known-good catalog). Stored on
  `brand_profiles.fonts_json`; ads load the matched fonts via a Google Fonts
  `<link>`.
- **Ecommerce product ads.** `EcommerceDetector` fingerprints Shopify /
  WooCommerce / Magento / BigCommerce / etc. (structural asset signatures +
  schema.org `Product`, not bare brand words → avoids false positives like a
  payments site that merely mentions "WooCommerce"). When a shop is detected,
  `ProductScraper` pulls products (title / price / image / url) from the
  crawl via JSON-LD + microdata, and `GenerateProductAdsJob` builds **20
  product remarketing ads** (one product per ad, image-capable templates),
  persisting products into the existing `ProductFeedItem` store.
- **Validation harness.** `php artisan templates:validate` renders every
  template, screenshots it via the Playwright renderer, and scores it with a
  Gemini-vision rubric (best-of-N rounds). `php artisan templates:test-brands`
  does the same against real brand sites (real colors/fonts/logos/images).
  Used to iterate the 20 templates to ≥82/100 before shipping.
- **Schema**: `brand_profiles.fonts_json`, `is_ecommerce`,
  `ecommerce_platform`. New `AdVariant.source_type` values `template` /
  `product` (+ matching campaign-grid badges).

### Merged Gemini brand + ads call
Combined the brand-summary and 30-concept generation into a single
Gemini 3.5-flash call (was two sequential 2.5-flash calls). The schema
now requires per-concept `position` (top/middle/bottom) and `font_sizes`
{headline, sub, cta} — both consumed by the HTML pipeline so typography
is anchored to a target instead of free-styled per ad.

### HTML-only frontend (Playwright dropped from critical path)
`/preview` and `/dashboard/campaigns/*` now serve ads as
`<iframe srcdoc="…">` tiles, with `transform: scale()` shrinking each
true-pixel iframe into the tile. Pipeline went from ~93 s with PNG render
to ~73 s without. The renderer container is kept idle for future PNG
export. `RenderAdAssetsJob` removed from the chain; `GenerateAdTemplatesJob`
became the finalizer.

### Top-up cron (+70 ads to 100 total)
`TopUpCampaignAdsJob` fired hourly by Laravel's scheduler. Uses the same
`GeminiBrandAndAdsService` to generate 70 more concepts and dispatches
the same per-variant fan-out. New `scheduler` service in compose runs
`php artisan schedule:work`.

### Gemini-vision creative scoring (replaced TRIBE v2 / Replicate)
Scoring moved off the Meta TRIBE v2 Cog model on Replicate (cold-boot
latency, GPU cost, CC-BY-NC licence) to **`GeminiScoringService`** — a
Gemini-vision call that rates each ad 0–100 on a creative rubric.
`ScoreAdVariantsBatchJob` batches variants (8 per call) on the low-priority
`reporting` queue, dispatched by the finalizer **after** the user sees
`preview_ready` so it never competes with the HTML build for Gemini
bandwidth. A deterministic mock score (CRC32 → beta-like distribution) is
the offline/quota fallback so the UI is always populated;
`creative_score_meta.rationale` carries the model's one-line justification
(surfaced as the score-badge tooltip). The old `docker/scorer/` Cog model,
`CreativeScoringService`, and `ScoreAdVariantJob` were removed.

### Brand grounding fixes
- **Cloudflare async**: the v2 browser-rendering API returns a job UUID;
  `CloudflareCrawler` now polls it for up to 60 s before falling back to
  a direct-HTTP fetch of the homepage + a few common paths and stripping
  to text. This guarantees Gemini always sees real content rather than
  inventing a brand from the domain name.
- **Stricter prompt**: when the crawl returns empty, the merged
  brand+ads prompt explicitly forbids guessing industry/products from
  the URL and tells Gemini to leave fields neutral. Fixed the original
  bug where `cloudlab-solutions.com` came out as cloud infrastructure
  instead of web-to-print.
- **Logo color extraction**: client-side JS quantises the uploaded logo
  to its 5 dominant hex colors. Submitted with the form, persisted on
  `onboarding_sessions.logo_colors_json`, pinned into the Gemini prompt
  as the canonical palette, and written through to
  `brand_profiles.visual_identity_json` so the HTML pipeline picks them
  up. The modal shows a live palette preview.
- **English-locale crawl**: `Accept-Language: en-US,en;q=0.9` on both
  the Cloudflare browser-rendering call and the direct-HTTP fallback,
  so the Frankfurt droplet doesn't get the German Stripe homepage.

### Production deploy
- `deploy/deploy.sh` provisions a fresh DigitalOcean droplet via the API,
  installs Docker via cloud-init, rsyncs the codebase, scp's a sanitised
  production `.env` (with CRLF stripped — `\r` in API tokens silently
  broke Gemini, Replicate, and Cloudflare), runs migrations, brings the
  stack up, and prints the public URL.
- Shared `storage-public` Docker volume across **app + 8 workers +
  scheduler + nginx** so generated ad images are visible to every
  container that needs them. Without this, workers' downloads landed on
  their ephemeral fs and nginx 404'd every `/storage/*` request.
- `AdImageGenerationService` cache now verifies the file actually exists
  on disk before reusing it (after a volume recreate, the old prompt-hash
  cache rows point at ghosts).

### TLS via Cloudflare
- DNS for `layout.ai` repointed at the droplet IP, proxied through
  Cloudflare.
- SSL mode: Flexible (visitor↔CF HTTPS, CF↔origin HTTP), Always-Use-HTTPS
  + Automatic-HTTPS-Rewrites both on. HTTPS via `https://layout.ai`,
  HTTP→HTTPS 301 redirect.
- Existing `ad_images.stored_url` rewritten from `http://164.92.236.89`
  to `https://layout.ai` to avoid mixed-content warnings in iframes.

### UI polish
- Landing: new dark "The Pipeline" section between the hero and the
  Daily-ads grid — four alternating phases (Generate → Score with TRIBE
  v2 → Test on GDN → Keep winners) with bespoke SVG visuals per phase
  and a 1,024 → 307 → 30 → 10 funnel summary at the bottom.
- `/preview`: hide empty Tone/CTAs, asymmetric grid where leaderboards
  span 2-3 columns and skyscrapers span 2 rows, smooth count-up status
  pill, shimmer loading placeholder.
- Dashboard: greeting line, enriched metric cards (corner glow, mini
  visualization), 3-up square thumbnail strip on the campaign card.
- App sidebar: workspace name + brand color swatch up top, credit
  balance pinned to viewport bottom, icons + active bar on nav items.
- Campaign page: creative-score badge per tile (color-coded by quartile)
  + scoring summary band (variants, scored/total, avg, top) + sort by
  score.
- App layout switched to `h-screen flex overflow-hidden` so the sidebar
  stays in viewport on tall pages.

### Polish + ops fixes (everything else)
- Gemini 2.0-flash → 2.5-flash (model deprecated for new accounts)
- `GeminiClient::generateJson()` accepts a `$modelOverride` so the
  brand+ads merged call uses 3.5-flash without affecting other callers
- `Http::timeout` 60→180 s on `GeminiClient` to survive merged-call latency
- 16-worker scaling + `thinkingConfig.thinkingBudget=0` to disable
  Gemini 2.5-flash's reasoning pre-pass (saves ~50% latency)
- `BrandProfile` colors_json + visual_identity_json overridden with
  logo palette at brand-save time even if Gemini ignored the prompt

## Compliance & safety

- Every `ad_concept.policy_status` defaults to `pending`
- `NewsEventService` filters risk_score > 0.4 + Gemini prompt forbids
  tragedies, politics, fake endorsements, unsupported medical/financial
- Every image prompt forced to end with "no text, no logo, no watermark"
- Creative scoring is Gemini-vision — no third-party model licence to clear
  before charging customers (TRIBE v2's CC-BY-NC constraint is gone)
- `audit_logs` table provisioned for prompt → output → image → ad →
  approval traceability

## Known follow-ups

- **HTML5 ZIP export** per plan section 17 — kept as PNG-only via
  Playwright when needed, no zipped HTML banner build yet.
- **Hourly top-up still uses Gemini** — `TopUpCampaignAdsJob` tops campaigns
  to 100 via the Gemini path; could prefer templates to cut cost (the new
  `TemplateAdRenderer` makes this cheap).
- **Product ad currency** follows the shop's geo-IP at scrape time; force a
  storefront locale/currency for consistent pricing.
- **Shopify / WooCommerce feed connectors** — managed CSV / XML / Merchant
  Center feeds (the crawl-time `ProductScraper` covers ad generation today).
- **Vite build** for Tailwind so production CSS is purged (Play CDN ships
  the whole bundle).
- **R2/S3 storage** — filesystem driver + env keys staged, FILESYSTEM_DISK
  switch is one line when credentials exist.
