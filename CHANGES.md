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
- **Creative scoring** — Meta TRIBE v2 on Replicate GPU
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
      GeminiBrandAndAdsService  one merged call: brand profile + 30 concepts
      GeminiHtmlAdService       per-ad HTML generator (batched x5)
      AdImageGenerationService  runmyprint + SHA-256 cache w/ file-existence validate
      AdTemplateService         deterministic HTML fallback
      AdRenderService           Playwright bridge (idle on prod)
      CloudflareCrawler         async-poll + direct-HTTP fallback
      CreativeScoringService    Replicate / TRIBE v2 client with mock fallback
      NewsEventService          daily-event hooks
  docker/
    app/                        PHP 8.4-FPM container
    nginx/                      dev nginx vhost
    renderer/                   Node + Playwright /render service
    scorer/                     ★ Cog model for Replicate (TRIBE v2 + visual-cortex aggregation)
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
  → SummarizeBrandWithGeminiJob  (ai)    one Gemini 3.5-flash call returns
                                          brand + 30 concepts + font_sizes + position
  → GenerateAdImagePromptsJob    (ai)
  → GenerateAdImagesJob          (image) fans out GenerateAdImageJob + BuildAdHtmlBatchJob
  → GenerateAdTemplatesJob       (ai)    finalizer: polls for HTML done, flips preview_ready
```

Per-variant fan-out: `GenerateAdImageJob` → `BuildAdHtmlBatchJob`
(5-at-a-time) → `ScoreAdVariantJob` (TRIBE v2 via Replicate).

Hourly: `layout:top-up-campaigns` (Laravel scheduler) tops every campaign
up to 100 variants. Per-campaign `Cache::lock` prevents the CLI command
and the scheduler from racing each other.

## Significant changes

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

### TRIBE v2 creative scoring
`docker/scorer/` is a Cog-packaged Replicate model that wraps
`facebook/tribev2`. Each ad's runmyprint base image is wrapped in a 2-s
still-frame video (TRIBE v2 only accepts video/audio/text, not images),
passed through the model, and aggregated:

```
score = clip(0, 100, 50 + 12 * z(mean(|activation| over visual_cortex)))
```

The visual cortex mask is built from FreeSurfer / Destrieux atlas labels
on the fsaverage5 mesh at container startup (with an anatomical
approximation fallback for offline contexts).

Replicate deployment `iberescu/layout-tribe-scorer` runs on `gpu-l40s`
with `min_instances=0, max_instances=3` — autoscale-to-zero for cost,
burst to 3 replicas when a campaign's 30 ads dispatch at once. First
call after idle eats ~4 min cold boot; warm predicts are ~75 s.

`CreativeScoringService` polls for up to 10 min then falls back to a
deterministic mock score (CRC32 → beta-like distribution) so the UI is
always populated. Per-variant `creative_score_meta` carries the raw
brain-region activations so the aggregation formula can be re-derived
without re-scoring.

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
- `ScoreAdVariantJob::$timeout` 180→720 s + worker `--timeout=800` to
  absorb TRIBE v2 cold boot
- 16-worker scaling + `thinkingConfig.thinkingBudget=0` to disable
  Gemini 2.5-flash's reasoning pre-pass (saves ~50% latency)
- `BrandProfile` colors_json + visual_identity_json overridden with
  logo palette at brand-save time even if Gemini ignored the prompt

## Compliance & safety

- Every `ad_concept.policy_status` defaults to `pending`
- `NewsEventService` filters risk_score > 0.4 + Gemini prompt forbids
  tragedies, politics, fake endorsements, unsupported medical/financial
- Every image prompt forced to end with "no text, no logo, no watermark"
- TRIBE v2 is CC BY-NC — fine for research / MVP, commercial license
  required from Meta before charging customers
- `audit_logs` table provisioned for prompt → output → image → ad →
  approval traceability

## Known follow-ups

- **Replicate min_instances=1** for sub-100 ms scoring (vs current
  cold-boot-on-idle). $2-3k/mo for an always-on l40s; deferred until
  real traffic justifies it.
- **HTML5 ZIP export** per plan section 17 — kept as PNG-only via
  Playwright when needed, no zipped HTML banner build yet.
- **Shopify / WooCommerce feed connectors** — only CSV / XML / Merchant
  Center at MVP per plan section 13.2.
- **Vite build** for Tailwind so production CSS is purged (Play CDN ships
  the whole bundle).
- **R2/S3 storage** — filesystem driver + env keys staged, FILESYSTEM_DISK
  switch is one line when credentials exist.
