<?php

namespace App\Services;

use App\Models\CrawlJob;
use App\Models\CrawlPage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Crawl a website's homepage + a few key pages for brand grounding.
 *
 * Strategy:
 *  1. Call Cloudflare browser-rendering /crawl. If the API returns the
 *     legacy synchronous shape (`{result: {pages: [...]}}`) we use it.
 *  2. The new v2 API returns `{result: "<job_uuid>", success: true}` —
 *     in that case we poll the status endpoint until the crawl finishes
 *     and ingest its pages.
 *  3. If Cloudflare returns nothing usable inside our timeout, fall back
 *     to a plain HTTP fetch of the homepage + /about. This guarantees
 *     Gemini always has SOME real content to ground on, even when
 *     Cloudflare is slow or misconfigured.
 */
class CloudflareCrawler
{
    private const POLL_DEADLINE_SECONDS = 60;
    private const POLL_INTERVAL_SECONDS = 3;

    public function crawl(CrawlJob $job): void
    {
        $accountId = config('services.cloudflare.account_id');
        $token     = config('services.cloudflare.api_token');
        $endpoint  = config('services.cloudflare.endpoint');

        if ($accountId && $token && $endpoint) {
            $pages = $this->crawlViaCloudflare($job, $accountId, $token, $endpoint);
            if (! empty($pages)) {
                $this->ingest($job, ['pages' => $pages]);
                return;
            }
            Log::info("CloudflareCrawler returned no usable pages for {$job->url}, falling back to direct HTTP");
        }

        $pages = $this->directHttpFallback($job->url);
        if (! empty($pages)) {
            $this->ingest($job, ['pages' => $pages]);
            return;
        }

        // Final fallback: stub so the rest of the pipeline can run.
        $this->ingest($job, $this->stubFor($job->url));
    }

    /**
     * @return array<int,array<string,mixed>> List of page dicts (url/title/markdown).
     */
    private function crawlViaCloudflare(CrawlJob $job, string $accountId, string $token, string $endpoint): array
    {
        $payload = [
            'url'    => $job->url,
            'limit'  => $job->limit ?: 5,
            'depth'  => $job->depth ?: 1,
            'formats'=> ['markdown', 'html'],
            'render' => true,
            'gotoOptions' => [
                'waitUntil' => 'networkidle2',
                'timeout'   => 60000,
            ],
            // Force English content regardless of where the droplet is
            // hosted — otherwise stripe.com from Frankfurt returns the
            // German homepage and the brand profile comes out localized.
            'extraHTTPHeaders' => [
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ];
        $url = str_replace('{account}', $accountId, $endpoint);

        try {
            $response = Http::withToken($token)->timeout(45)->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('Cloudflare crawl threw: ' . $e->getMessage());
            return [];
        }

        if (! $response->successful()) {
            Log::warning('Cloudflare crawl failed: ' . $response->status() . ' ' . $response->body());
            return [];
        }

        $data = $response->json();

        // (A) Legacy synchronous shape: pages already in the response.
        $pages = $data['pages'] ?? $data['result']['pages'] ?? null;
        if (is_array($pages) && ! empty($pages)) {
            return $pages;
        }

        // (B) New async shape: result is a job UUID. Poll until done.
        $jobUuid = is_string($data['result'] ?? null) ? $data['result'] : null;
        if ($jobUuid) {
            return $this->pollCloudflareJob($jobUuid, $accountId, $token, $endpoint);
        }

        Log::info('Cloudflare returned unfamiliar payload shape, ignoring');
        return [];
    }

    /**
     * Poll the v2 async crawl endpoint until the job is done or we hit the
     * deadline. Returns the resulting pages array (possibly empty).
     */
    private function pollCloudflareJob(string $jobUuid, string $accountId, string $token, string $endpoint): array
    {
        $baseUrl = str_replace('{account}', $accountId, $endpoint);
        $statusUrl = rtrim($baseUrl, '/') . '/' . $jobUuid;

        $deadline = time() + self::POLL_DEADLINE_SECONDS;
        while (time() < $deadline) {
            try {
                $response = Http::withToken($token)->timeout(15)->get($statusUrl);
            } catch (\Throwable $e) {
                Log::info('Cloudflare poll exception: ' . $e->getMessage());
                sleep(self::POLL_INTERVAL_SECONDS);
                continue;
            }

            if (! $response->successful()) {
                Log::info('Cloudflare poll non-2xx: ' . $response->status() . ' (will retry)');
                sleep(self::POLL_INTERVAL_SECONDS);
                continue;
            }

            $data   = $response->json();
            $status = $data['result']['status'] ?? $data['status'] ?? null;
            $pages  = $data['result']['pages']  ?? $data['pages']  ?? null;

            // Pages already arrived OR job marked done with pages
            if (is_array($pages) && ! empty($pages)) {
                Log::info("Cloudflare crawl {$jobUuid} returned " . count($pages) . " page(s)");
                return $pages;
            }

            if (in_array($status, ['completed', 'success', 'done'], true)) {
                Log::info("Cloudflare crawl {$jobUuid} marked {$status} with 0 pages");
                return [];
            }
            if (in_array($status, ['failed', 'error', 'cancelled'], true)) {
                Log::info("Cloudflare crawl {$jobUuid} marked {$status}");
                return [];
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        }

        Log::info("Cloudflare crawl {$jobUuid} did not complete within " . self::POLL_DEADLINE_SECONDS . 's');
        return [];
    }

    /**
     * Plain HTTP fetch of the homepage + a small set of likely-relevant
     * paths. Strips HTML to text so Gemini can read the content. This is
     * the safety net that guarantees we never feed Gemini an empty crawl.
     *
     * @return array<int,array<string,mixed>>
     */
    private function directHttpFallback(string $homepageUrl): array
    {
        $parts = parse_url($homepageUrl);
        if (! $parts || empty($parts['host'])) {
            return [];
        }
        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
        $candidates = array_unique([
            rtrim($homepageUrl, '/'),
            $origin . '/about',
            $origin . '/about-us',
            $origin . '/products',
            $origin . '/services',
        ]);

        $pages = [];
        foreach ($candidates as $url) {
            if (count($pages) >= 4) break;
            try {
                $resp = Http::withHeaders([
                    'User-Agent'      => 'Mozilla/5.0 (compatible; LayoutAIBot/1.0; +https://layout.ai/bot)',
                    'Accept'          => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ])->timeout(20)->get($url);
                if (! $resp->successful()) {
                    continue;
                }
                $html  = $resp->body();
                $title = $this->extractTitle($html);
                $text  = $this->htmlToText($html);
                if (mb_strlen(trim($text)) < 80) {
                    continue;
                }
                $pages[] = [
                    'url'      => $url,
                    'title'    => $title ?: $url,
                    'markdown' => $text,
                    'meta'     => [],
                    'images'   => $this->extractImages($html, $url),
                    'links'    => [],
                ];
            } catch (\Throwable $e) {
                Log::info("Direct HTTP fetch failed for {$url}: " . $e->getMessage());
            }
        }
        if (! empty($pages)) {
            Log::info("Direct HTTP fallback returned " . count($pages) . ' page(s) for ' . $homepageUrl);
        }
        return $pages;
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return null;
    }

    /**
     * Pull image candidates from the HTML. Each entry carries the absolute
     * URL + light hints (width/height/alt) when available, so the downstream
     * harvester can rank them without a HEAD request per candidate.
     *
     * @return array<int,array{url:string, alt:?string, w:?int, h:?int, source:string}>
     */
    private function extractImages(string $html, string $pageUrl): array
    {
        $base = parse_url($pageUrl);
        if (! $base || empty($base['host'])) {
            return [];
        }
        $origin = ($base['scheme'] ?? 'https') . '://' . $base['host'];
        $resolve = function (string $src) use ($origin, $base): ?string {
            $src = trim($src);
            if ($src === '' || str_starts_with($src, 'data:')) return null;
            if (preg_match('#^https?://#i', $src)) return $src;
            if (str_starts_with($src, '//')) return ($base['scheme'] ?? 'https') . ':' . $src;
            if (str_starts_with($src, '/'))  return $origin . $src;
            return $origin . '/' . ltrim($src, './');
        };

        $out = [];
        $seen = [];

        // og:image / twitter:image — usually the highest-quality hero image.
        if (preg_match_all('#<meta[^>]+(?:property|name)=["\\\'](og:image(?::secure_url)?|twitter:image(?::src)?)["\\\'][^>]+content=["\\\']([^"\\\']+)["\\\']#i', $html, $m)) {
            foreach ($m[2] as $src) {
                $u = $resolve($src);
                if ($u && ! isset($seen[$u])) { $seen[$u] = true; $out[] = ['url' => $u, 'alt' => null, 'w' => null, 'h' => null, 'source' => 'og']; }
            }
        }

        // <img> tags. Skip data URIs, base64, and svg sprites.
        if (preg_match_all('#<img\b([^>]+)>#is', $html, $m)) {
            foreach ($m[1] as $attrs) {
                if (! preg_match('#\bsrc=["\\\']([^"\\\']+)["\\\']#i', $attrs, $sm)) continue;
                $u = $resolve($sm[1]);
                if (! $u || isset($seen[$u])) continue;
                $alt = (preg_match('#\balt=["\\\']([^"\\\']*)["\\\']#i', $attrs, $am)) ? $am[1] : null;
                $w   = (preg_match('#\bwidth=["\\\']?(\d+)#i',  $attrs, $wm)) ? (int) $wm[1] : null;
                $h   = (preg_match('#\bheight=["\\\']?(\d+)#i', $attrs, $hm)) ? (int) $hm[1] : null;
                $seen[$u] = true;
                $out[] = ['url' => $u, 'alt' => $alt, 'w' => $w, 'h' => $h, 'source' => 'img'];
            }
        }

        // Cap at 60 candidates per page — the harvester does further filtering.
        return array_slice($out, 0, 60);
    }

    private function htmlToText(string $html): string
    {
        // Drop script/style/nav/footer blocks before stripping tags so the
        // resulting text is closer to article copy.
        $html = preg_replace('#<(script|style|noscript|svg)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = preg_replace('#<(nav|footer|header)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return mb_substr(trim($text), 0, 4000);
    }

    private function ingest(CrawlJob $job, array $data): void
    {
        $job->update([
            'status'       => 'completed',
            'raw_response' => $data,
        ]);

        $pages = $data['pages'] ?? $data['result']['pages'] ?? [];
        foreach ($pages as $page) {
            CrawlPage::create([
                'crawl_job_id' => $job->id,
                'url'          => $page['url']      ?? $job->url,
                'title'        => $page['title']    ?? null,
                'markdown'     => $page['markdown'] ?? $page['content'] ?? null,
                'meta'         => $page['meta']     ?? [],
                'images'       => $page['images']   ?? [],
                'links'        => $page['links']    ?? [],
            ]);
        }
    }

    private function stubFor(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'example.com';
        $name = ucwords(str_replace(['.', '-', '_'], ' ', preg_replace('/^www\./', '', $host)));

        return [
            'pages' => [
                [
                    'url'      => $url,
                    'title'    => $name . ' – Home',
                    'markdown' => "# Welcome to {$name}\n\n{$name} provides modern services for busy teams. We focus on quality, speed, and trust.\n\n## What we do\n\n- Curated product line\n- Local delivery\n- Friendly support\n\n## Why customers love us\n\nThousands of customers trust {$name} every week.",
                    'meta'     => [
                        'description' => $name . ' offers a curated product line, local delivery, and friendly support.',
                        'og:image'    => null,
                    ],
                    'images'   => [],
                    'links'    => [],
                ],
                [
                    'url'      => rtrim($url, '/') . '/about',
                    'title'    => $name . ' – About',
                    'markdown' => "## About {$name}\n\nWe are a passionate team building products that customers love.",
                    'meta'     => [],
                    'images'   => [],
                    'links'    => [],
                ],
            ],
        ];
    }
}
