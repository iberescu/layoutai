# Layout.ai – Build Changelog

Running log of everything implemented from `LayoutAI_Tech_Plan.pdf`
(version 1.0, May 15, 2026). Validated end-to-end on Docker.

## Source plan

Layout.ai Technical Implementation Plan. Prepared for Ionut Berescu.
Product URL: `layout.ai/create`. Implementation follows the 7-phase MVP
roadmap in section 16, with all routes, data model, queue chain, and
view tree from the document.

## Tech stack (plan section 3.1)

- **Backend** – Laravel 11 (PHP 8.4) monolith
- **Frontend** – Blade + Tailwind CSS + Alpine.js (Play CDN for MVP)
- **DB** – PostgreSQL 16, `jsonb` for brand + strategy columns
- **Queue** – Redis 7 + Laravel Queues
- **Image generation** – `runmyprint` `image2.php?prompt=` endpoint
- **Rendering** – Node + Playwright (Chromium) HTTP service
- **AI text** – Gemini Flash (`gemini-2.0-flash`) with strict JSON output
- **Storage** – local `public` disk (R2/S3-compatible env keys staged)

External services (Cloudflare crawl, Gemini, runmyprint) are wrapped in
service classes. When API keys are missing, they fall back to
deterministic stub data so the full pipeline runs offline/CI.

## Repo layout

```
layout.ai/
  app/                     Laravel application
  docker/
    app/Dockerfile         PHP 8.4-FPM + PG + Redis extensions + entrypoint
    app/entrypoint.sh      Fixes storage permissions on container boot
    nginx/default.conf     Nginx vhost
    renderer/Dockerfile    Node + Playwright base image
    renderer/server.js     /render endpoint (HTML → PNG/JPG)
    renderer/package.json
  docker-compose.yml       app, nginx, postgres, redis, worker, renderer
  CHANGES.md               this file
  README.md                quickstart + routes
  .env                     top-level overrides for compose
  .gitignore
```

## Phase progress

- [x] Phase 0 – Docker scaffolding + Laravel install
- [x] Phase 1 – Frontend shell (marketing, modal, processing, preview, claim, dashboard)
- [x] Phase 2 – Onboarding backend (sessions, uploads, Cloudflare crawl)
- [x] Phase 3 – AI generation (Gemini brand summary + ad concepts)
- [x] Phase 4 – Image + render (runmyprint endpoint + Playwright renderer)
- [x] Phase 5 – Auth + campaign (signup, workspace, $500 credit ledger)
- [x] Phase 6 – Integrations (tracking pixel + CSV/XML/Merchant feed)
- [x] Phase 7 – Reporting + testing (creative score, top ads, daily events)
- [x] Docker validation – full chain produces 30 ads, all dashboard pages 200 OK

## Routes (plan section 5.1)

| Method  | Path                                | Controller                  |
| ------- | ----------------------------------- | --------------------------- |
| GET     | `/create`                           | `CreateController@index`    |
| POST    | `/create/start`                     | `OnboardingController@start`|
| GET     | `/create/{session}/processing`      | `…@processing`              |
| GET     | `/create/{session}/status`          | `…@status` (JSON)           |
| GET     | `/create/{session}/preview`         | `…@preview`                 |
| GET/POST| `/create/{session}/claim`           | `…@claim` / `@storeAccount` |
| GET     | `/login` + POST                     | `AuthController`            |
| GET     | `/pixel.js`                         | `PixelController@script`    |
| POST    | `/p/event`                          | `PixelController@event` (CSRF-exempt) |
| GET     | `/healthz`                          | inline                      |
| GET     | `/dashboard`                        | `DashboardController`       |
| GET     | `/dashboard/campaigns/{campaign}`   | `CampaignController@show`   |
| GET/POST| `/dashboard/integrations`           | `IntegrationController`     |
| GET     | `/dashboard/reporting`              | `ReportingController`       |
| GET     | `/dashboard/settings`               | `SettingsController`        |

## Queue chain (plan section 5.2)

`Bus::chain([...])` from `OnboardingController@start`:

```
CrawlWebsiteJob          (queue: crawl)
  → ExtractBrandJob       (queue: ai)
  → SummarizeBrandWithGeminiJob (queue: ai)
  → GenerateAdConceptsJob (queue: ai)
  → GenerateAdImagePromptsJob (queue: ai)
  → GenerateAdImagesJob   (queue: image)
  → GenerateAdTemplatesJob (queue: ai)
  → RenderAdAssetsJob     (queue: render)
```

Additional jobs: `GenerateDailyEventAdsJob`, `SyncProductFeedJob`,
`VerifyPixelJob`. Worker container runs `queue:work --queue=high,crawl,ai,image,render,reporting,default`.

## Data model (plan section 6)

Migrations under `app/database/migrations/2026_05_15_*` create all
tables from the plan:

`workspaces`, `workspace_members`, `onboarding_sessions`,
`brand_profiles`, `crawl_jobs`, `crawl_pages`, `uploaded_assets`,
`campaigns`, `ad_concepts`, `ad_variants`, `ad_images`, `ad_renders`,
`pixel_sites`, `pixel_events`, `conversion_events`, `product_feeds`,
`product_feed_items`, `credit_ledger`, `news_event_hooks`,
`generation_jobs`, `audit_logs`.

Notable structural choices:

- `campaigns.workspace_id` is nullable so the pre-signup preview campaign
  exists before the user creates an account; the existing campaign is
  re-parented at `storeAccount` time rather than copying variants.
- `ad_images.source_url`, `ad_images.stored_url`, `ad_renders.asset_url`
  are `text` columns because the `runmyprint?prompt=...` URLs and storage
  URLs routinely exceed varchar(255).
- All money is stored as integer cents (`amount_cents`).
- All JSON columns use Postgres `jsonb`.

## Service classes (plan section 3.3)

| Service                       | Responsibility                                      |
| ----------------------------- | --------------------------------------------------- |
| `CloudflareCrawler`           | POSTs to Cloudflare browser-rendering `/crawl`; stub fallback |
| `GeminiClient`                | Thin Gemini Flash JSON-mode wrapper                 |
| `GeminiBrandService`          | Crawl context → brand profile JSON (with stub)      |
| `GeminiAdService`             | Brand → 30/N ad concepts across 10 sizes (with stub)|
| `AdImageGenerationService`    | runmyprint call, SHA-256 prompt caching, SVG fallback|
| `AdTemplateService`           | Builds Blade-like HTML/CSS for the ad layout         |
| `AdRenderService`             | POSTs HTML to renderer; rewrites localhost → nginx  |
| `PixelVerificationService`    | Event-source-of-truth check + crawl fallback        |
| `ProductFeedService`          | CSV / XML / Google Merchant ingestion               |
| `NewsEventService`            | Eligible event hooks + seasonal fallbacks           |
| `ReportingService`            | Summary metrics + creative-score ranking            |
| `CreditService`               | $500 grant + spend debits                           |

## Visual design

Implements the design tokens from plan section 4.1 verbatim
(`#F8FAFC` bg, `#0F172A` ink, `#2563EB` primary, `#7C3AED` accent,
`#10B981` success, etc.) wired into the Tailwind config inside both
`layouts/marketing.blade.php` and `layouts/app.blade.php`.

## Compliance & safety

- All ad concepts go through a `policy_status` column and start in
  `pending`.
- `NewsEventService` filters out anything above `risk_score=0.4` and the
  Gemini prompt template forbids tragedies, politics, fake endorsements,
  unsupported medical/financial claims.
- Every image prompt is normalized (`AdImageGenerationService::cleanPrompt`)
  and forced to end with "no text, no logo, no watermark" by the prompt
  template.
- `audit_logs` table is provisioned for full prompt → output → image →
  ad → approval traceability per plan section 15.

## Docker validation (end-to-end)

```
$ docker compose ps
NAME                IMAGE                      STATUS                   PORTS
layoutai-app        layoutai/app:latest        Up                       9000/tcp
layoutai-nginx      nginx:1.27-alpine          Up                       0.0.0.0:8088->80/tcp
layoutai-postgres   postgres:16-alpine         Up (healthy)             5432/tcp
layoutai-redis      redis:7-alpine             Up (healthy)             6379/tcp
layoutai-renderer   layoutai/renderer:latest   Up                       3000/tcp
layoutai-worker     layoutai/app:latest        Up                       (queue:work)
```

Validation results:

- `POST /create/start` → onboarding session created, 8-step queue chain dispatched.
- All 30 ad concepts generated (20 brand + 10 daily-event placeholders).
- All 30 image prompts called against the `runmyprint` endpoint and stored
  (real PNGs ~1 MB each, SHA-256 cache hits on repeats).
- All 30 ads rendered by the Playwright service (verified 2× DPR PNGs in
  `storage/app/public/generated/ad-renders/`).
- `GET /create/{uuid}/status` reports `progress=1.0` and `status=preview_ready`.
- `GET /create/{uuid}/preview` returns 200 and lists 30 ads.
- `POST /create/{uuid}/claim` creates user + workspace + $500 promotional
  credit + re-parents the preview campaign; redirects to `/dashboard`.
- `GET /dashboard` shows `$500.00` credit balance and `30` generated ads.
- `GET /dashboard/campaigns/1` lists all 30 variants with status filters.
- `GET /dashboard/integrations` allows pixel registration; `POST /p/event`
  ingests a `page_view` and updates `pixel_sites.status` to `receiving_events`.
- `GET /dashboard/reporting` shows summary metrics and the top-creative score table.
- `GET /dashboard/settings` shows brand + workspace info.
- `GET /healthz` returns `{"ok":true,...}`.

## Feedback round 1 (post initial commit)

Implemented in response to user feedback:

1. **Landing page redesigned** (`pages/create/index.blade.php`):
   - Sticky top promo banner (`$500 in free ad credits`) with gradient.
   - Larger hero with gradient-clip headline, live-pulse status chip, social proof.
   - Big floating "$500" promo card with three perk checkmarks and primary CTA.
   - "How it works" cards with bespoke SVG icons (globe/sparkles/grid/beaker/trophy).
   - Dark `Every display ad size covered` section with all 10 IAB sizes.
   - Daily-ads grid with colored category chips.
   - FAQ accordion swapped from `x-collapse` to `x-show + x-transition` to remove Alpine plugin dependency.
   - Final "Ready to generate your first 30 ads?" CTA card.

2. **Ad assembly now done by Gemini Flash** (`Services/GeminiHtmlAdService.php`):
   - New service replaces `AdTemplateService` for the templates step.
   - Prompts Gemini with the variant copy + AI image URL + brand colors + layout hint
     and asks for a strict-JSON `{html, css}` response with a sanitisation pass that
     strips `<script>` tags and clamps outer dimensions.
   - Falls back to `AdTemplateService` if Gemini fails or returns no HTML.

3. **Pipeline robustness fixes from validation**:
   - `GenerateAdTemplatesJob` / `GenerateAdImagesJob` / `RenderAdAssetsJob` all set
     `public int $timeout = 600`-900 and skip variants the previous attempt finished
     (idempotent retry).
   - Worker `--timeout=900 --memory=512` in `docker-compose.yml`.
   - `GeminiBrandService` and `GeminiAdService` schemas now include explicit
     `properties` so structured-output mode returns populated objects (was returning
     `{}` per item, leaving variant headlines NULL).
   - `GEMINI_MODEL` default updated from `gemini-2.0-flash` (no longer available to
     new users) to `gemini-2.5-flash`.
   - Cloudflare `account_id` auto-resolution: now provisioned from the user's account
     via the API. Real Cloudflare crawl pulls live HTML from stripe.com / apple.com.
   - Renderer (`server.js`) now adds a force-clip style tag + viewport reset before
     screenshotting so AI-authored HTML that overflows the ad container still
     produces an exact-size image.

4. **Real-API end-to-end test (stripe.com)**:
   - Cloudflare crawl on `https://www.stripe.com` returned populated content.
   - Gemini 2.5 Flash brand summary: company=`Stripe`, industry=`Fintech`,
     description correctly identifies financial infrastructure platform.
   - Gemini concepts produced 30 brand+event variants with copy like
     "Build with the Best. Scale with Stripe." and "Automate Your Finance. Focus on Growth."
   - runmyprint generated 30 ad backgrounds.
   - Gemini wrote 30 unique HTML documents (~1.2-1.5 KB each) that embed the AI image,
     headline, sub, and CTA button styled in the Stripe palette.
   - Playwright renders all 30 ads at exact IAB sizes.

5. **API keys** were placed in `.env` and `app/.env` (both gitignored) and verified
   to be picked up by the container env. Keys are NOT committed.

## Known follow-ups (out of scope for this implementation)

- Replace Tailwind Play CDN with a Vite build for production CSS purging.
- Switch from local `public` disk to Cloudflare R2/S3 once credentials exist
  (filesystem driver + env wired; just change `FILESYSTEM_DISK`).
- Replace stub Gemini and Cloudflare responses with real API calls — already
  active when the env keys are present.
- HTML5 ZIP export per plan section 17 (left for later).
- Shopify / WooCommerce feed connectors (plan section 13.2 marks them as
  "later").
