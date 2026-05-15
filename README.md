# Layout.ai

AI-driven display ad generation: scan a website, learn the brand,
generate 30 preview ads, then unlock the full 100/1,000-ad test
campaign after signup.

Implemented from `LayoutAI_Tech_Plan.pdf` (version 1.0, May 15, 2026).

## Stack

- **Backend** – Laravel 11 (PHP 8.4) monolith
- **Frontend** – Blade + Tailwind CSS + Alpine.js (Play CDN in MVP)
- **DB** – PostgreSQL 16 with `jsonb` brand/strategy columns
- **Queue** – Redis 7 + Laravel Queues (queues: high, crawl, ai, image, render, reporting, default)
- **Image generation** – `runmyprint` `image2.php?prompt=` endpoint
- **Rendering** – Node + Playwright (Chromium) HTTP service that turns Blade-rendered HTML into exact-size PNG/JPG
- **AI text** – Gemini Flash (`gemini-2.0-flash`) with strict JSON output

## Quickstart

```bash
docker compose build
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan storage:link
```

Then open <http://localhost:8088/create>.

## Environment

`app/.env` controls service credentials. Empty keys trigger deterministic
stub data so the full pipeline runs offline:

| Variable | Purpose |
| --- | --- |
| `GEMINI_API_KEY` | Gemini Flash brand + ad generation |
| `CLOUDFLARE_ACCOUNT_ID` / `CLOUDFLARE_API_TOKEN` | Cloudflare browser-rendering `/crawl` |
| `RUNMYPRINT_ENDPOINT` | Image endpoint, default `https://www.runmyprint.com/test/image2.php` |
| `RENDERER_URL` | Internal Playwright service (default `http://renderer:3000`) |

## Key routes

- `GET  /create` – marketing + create-modal
- `POST /create/start` – create onboarding session + dispatch queue chain
- `GET  /create/{session}/processing` – polling UI
- `GET  /create/{session}/status` – JSON state
- `GET  /create/{session}/preview` – 30-ad preview
- `GET  /create/{session}/claim` + `POST` – signup + $500 credit grant
- `GET  /dashboard` – authenticated overview
- `GET  /dashboard/campaigns/{campaign}` – ad grid, filters, statuses
- `GET  /dashboard/integrations` – pixel + product feed
- `GET  /dashboard/reporting` – metrics + creative-score top ads
- `GET  /pixel.js` – tracking pixel script
- `POST /p/event` – pixel event ingestion (CSRF-exempt)

## Queue chain

```
CrawlWebsiteJob
  → ExtractBrandJob
  → SummarizeBrandWithGeminiJob
  → GenerateAdConceptsJob
  → GenerateAdImagePromptsJob
  → GenerateAdImagesJob
  → GenerateAdTemplatesJob
  → RenderAdAssetsJob
```

See [CHANGES.md](CHANGES.md) for full implementation notes and phase log.
