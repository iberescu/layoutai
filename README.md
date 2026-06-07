# Layout.ai

AI-driven display ad generation, evaluation, and rollout. Scan a website,
learn the brand, generate 100 ads, ship the strongest 30 to Google Display
Network, keep the 10 that earned it.

Live at **https://layout.ai**.

## Stack

| Layer | Tech |
| --- | --- |
| Web app | Laravel 11 / PHP 8.4-FPM |
| Frontend | Blade + Tailwind (Play CDN) + Alpine.js |
| Database | PostgreSQL 16 with `jsonb` brand/strategy columns |
| Queue | Redis 7 + Laravel Queues (workers, scheduler) |
| Brand + Ads | Gemini 3.5 Flash (single merged call) |
| Per-ad HTML | Gemini 2.5 Flash (batched 5 ads per call) |
| Ad imagery | runmyprint `/image2.php?prompt=…` |
| Optional PNG | Node + Playwright (Chromium) — kept idle, not on critical path |

## Quickstart (local Docker)

```bash
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan storage:link
```

Open <http://localhost:8088/create>.

## Environment

`.env` (top level, gitignored) holds tokens for the compose stack.
`app/.env` holds Laravel-specific config. Empty keys trigger deterministic
stubs so the pipeline runs offline.

| Variable | Purpose |
| --- | --- |
| `GEMINI_API_KEY` | Brand profile + per-ad HTML |
| `GEMINI_MODEL` | HTML model (default `gemini-2.5-flash`) |
| `GEMINI_COMBINED_MODEL` | Brand+ads merged-call model (default `gemini-3.5-flash`) |
| `CLOUDFLARE_ACCOUNT_ID` / `CLOUDFLARE_API_TOKEN` | Browser-rendering crawl |
| `RUNMYPRINT_ENDPOINT` | Ad image generator |

## Key routes

| Method | Path | What |
| --- | --- | --- |
| GET   | `/create` | Marketing + create-modal |
| POST  | `/create/start` | Onboarding session + queue chain |
| GET   | `/create/{uuid}/processing` | Polling UI |
| GET   | `/create/{uuid}/status` | JSON state |
| GET   | `/create/{uuid}/preview` | 30-ad preview (live-updating iframes) |
| GET/POST | `/create/{uuid}/claim` | Signup + $500 credit grant |
| GET   | `/dashboard` | Authenticated overview |
| GET   | `/dashboard/campaigns/{campaign}` | Ad grid + filters |
| GET   | `/dashboard/reporting` | Metrics table |
| GET   | `/pixel.js` | Tracking pixel |
| POST  | `/p/event` | Pixel event ingestion (CSRF-exempt) |

## Pipeline

```
CrawlWebsiteJob                                                   (queue: crawl)
  → ExtractBrandJob                                               (queue: ai)
  → SummarizeBrandWithGeminiJob   (one Gemini 3.5-flash call →    (queue: ai)
                                   brand profile + 30 concepts +
                                   font sizes + positions)
  → GenerateAdImagePromptsJob                                     (queue: ai)
  → GenerateAdImagesJob           (fan-out: runmyprint per ad)   (queue: image)
  → GenerateAdTemplatesJob        (finalizer: polls for all HTML  (queue: ai)
                                   built, then preview_ready)
```

Per-variant jobs run outside the chain in parallel: `GenerateAdImageJob`,
`BuildAdHtmlBatchJob` (batched 5-at-a-time).

Hourly scheduler tops every campaign up to 100 variants:
`layout:top-up-campaigns` is `Schedule::command(...)->hourly()` with a
per-campaign `Cache::lock` to prevent the CLI command and the cron from
racing each other.

## Deploy to DigitalOcean

```bash
bash deploy/deploy.sh                    # provisions a fresh droplet
bash deploy/deploy.sh <existing-ip>      # incremental redeploy (~30s)
```

See `deploy/README.md` for full runbook.

## Source

[`CHANGES.md`](CHANGES.md) — running log of the implementation, including
the architecture decisions made along the way.
